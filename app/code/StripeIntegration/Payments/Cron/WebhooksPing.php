<?php

namespace StripeIntegration\Payments\Cron;

class WebhooksPing
{
    private $config;
    private $webhooksCollectionFactory;
    private $paymentElementCollection;
    private $paymentIntentCollection;
    private $webhooksSetup;
    private $helperFactory;
    private $paymentIntentHelper;
    private $cache;
    private $orderCollection;
    private $helper;
    private $checkoutSessionFactory;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook\CollectionFactory $webhooksCollectionFactory,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentElement\Collection $paymentElementCollection,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\Collection $paymentIntentCollection,
        \StripeIntegration\Payments\Model\CheckoutSessionFactory $checkoutSessionFactory,
        \StripeIntegration\Payments\Helper\WebhooksSetup $webhooksSetup,
        \StripeIntegration\Payments\Helper\GenericFactory $helperFactory,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Sales\Model\ResourceModel\Order\Collection $orderCollection
    ) {
        $this->config = $config;
        $this->webhooksCollectionFactory = $webhooksCollectionFactory;
        $this->paymentElementCollection = $paymentElementCollection;
        $this->paymentIntentCollection = $paymentIntentCollection;
        $this->checkoutSessionFactory = $checkoutSessionFactory;
        $this->webhooksSetup = $webhooksSetup;
        $this->helperFactory = $helperFactory;
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->cache = $cache;
        $this->orderCollection = $orderCollection;
    }

    public function execute()
    {
        $this->pingWebhookEndpoints();
        $this->cancelAbandonedPayments();
        $this->clearStaleData();
    }

    public function pingWebhookEndpoints()
    {
        $staleWebhooks = $this->webhooksCollectionFactory->create()->findStaleWebhooks();

        $stalePublishableKeys = [];
        foreach ($staleWebhooks as $webhookModel)
        {
            $stalePublishableKeys[] = $webhookModel->getPublishableKey();
        }

        $keys = $this->webhooksSetup->getAllActiveAPIKeys();

        foreach ($keys as $secretKey => $publishableKey)
        {
            if (!in_array($publishableKey, $stalePublishableKeys))
            {
                continue;
            }

            $this->config->initStripeFromSecretKey($secretKey);
            $stripe = $this->config->getStripeClient();

            $localTime = time();
            $product = $stripe->products->create([
               'name' => 'Webhook Ping',
               'type' => 'service',
               'metadata' => [
                    "pk" => $publishableKey
               ]
            ]);
            $timeDifference = $product->created - ($localTime + 1); // The 1 added second accounts for the delay in creating the product
            $this->cache->save($timeDifference, $key = "stripe_api_time_difference", $tags = ["stripe_payments"], $lifetime = 24 * 60 * 60);

            $product->delete();
        }
    }

    public function cancelAbandonedPayments($minAgeMinutes = 2 * 60, $maxAgeMinutes = 6 * 60, $output = null)
    {
        $timeDifference = $this->cache->load("stripe_api_time_difference");
        if (!is_numeric($timeDifference))
            $timeDifference = 0;

        $now = time() + $timeDifference;
        $fromTime = $now - ($maxAgeMinutes * 60);
        $toTime = $now - ($minAgeMinutes * 60);

        $keys = $this->webhooksSetup->getAllAPIKeys();
        $canceled = $processed = [];

        $params = [
            'limit' => 100,
            'created' => [
                'gte' => $fromTime,
                'lte' => $toTime
            ],
            'expand' => ['data.payment_method']
        ];

        foreach ($keys as $secretKey => $publishableKey)
        {
            $stripe = new \Stripe\StripeClient($secretKey);
            $paymentIntents = $stripe->paymentIntents->all($params);

            foreach ($paymentIntents->autoPagingIterator() as $paymentIntent)
            {
                if ($this->isAbandonedPayment($paymentIntent))
                {
                    $method = null;
                    $orderIncrementId = null;

                    if (empty($paymentIntent->metadata->{"Order #"}))
                    {
                        $orders = $this->getHelper()->getOrdersByTransactionId($paymentIntent->id);

                        if (count($orders) == 0)
                        {
                            // This payment intent may have not been created by the module.
                            continue;
                        }
                        else
                        {
                            $this->cancelOrders($orders, $paymentIntent, $output);

                            foreach ($orders as $order)
                            {
                                $method = $order->getPayment()->getMethod();
                                $orderIncrementId = $order->getIncrementId();
                            }
                        }
                    }
                    else
                    {
                        $order = $this->getHelper()->loadOrderByIncrementId($paymentIntent->metadata->{"Order #"});
                        if ($order)
                        {
                            $method = $order->getPayment()->getMethod();
                            $orderIncrementId = $order->getIncrementId();
                        }
                }

                    if ($output)
                    {
                        $output->writeln("<info>Canceling payment intent {$paymentIntent->id}</info>");
                    }

                    try
                    {
                        if ($method == "stripe_payments_checkout" && $orderIncrementId)
                        {
                            $stripeCheckoutSessionModel = $this->checkoutSessionFactory->create()->load($orderIncrementId, 'order_increment_id');
                            $checkoutSessionId = $stripeCheckoutSessionModel->getCheckoutSessionId();
                            if ($checkoutSessionId)
                            {
                                $stripe->checkout->sessions->expire($checkoutSessionId, []);
                                $canceled[] = $stripe->paymentIntents->retrieve($paymentIntent->id, []);
                            }
                        }
                        else
                        {
                            $canceled[] = $stripe->paymentIntents->cancel($paymentIntent->id, ['cancellation_reason' => 'abandoned']);
                        }
                    }
                    catch (\Exception $e)
                    {
                        $this->getHelper()->logError($e->getMessage(), $e);
                    }
                }
            }

            $setupIntents = $stripe->setupIntents->all($params);
            foreach ($setupIntents->autoPagingIterator() as $setupIntent)
            {
                if (in_array($setupIntent->status, ['processing', 'canceled', 'succeeded', 'requires_action']))
                    continue;

                $canceled[] = $stripe->setupIntents->cancel($setupIntent->id, ['cancellation_reason' => 'abandoned']);
            }
        }

        return $canceled;
    }

    protected function isAbandonedPayment($paymentIntent)
    {
        if ($this->paymentIntentHelper->isSuccessful($paymentIntent))
        {
            return false;
        }

        if ($this->paymentIntentHelper->requiresOfflineAction($paymentIntent))
        {
            return false;
        }

        if (!$this->paymentIntentHelper->canCancel($paymentIntent))
        {
            return false;
        }

        return true;
    }

    public function clearStaleData()
    {
        $age = 24 * 30 * 6; // 6 months
        $this->paymentElementCollection->deleteOlderThan($age);
        $this->paymentIntentCollection->deleteOlderThan($age);
    }

    public function cancelOrders($orders, $paymentIntent, $output = null)
    {
        foreach ($orders as $order)
        {
            if ($order->canCancel())
            {
                if ($output)
                {
                    $output->writeln("<info>Canceling order #{$order->getIncrementId()} for payment intent {$paymentIntent->id}</info>");
                }

                try
                {
                    $msg = __("Customer abandoned the cart. The payment session has expired.");
                    $this->getHelper()->addOrderComment($msg, $order);
                    $this->getHelper()->cancelOrCloseOrder($order);
                }
                catch (\Exception $e)
                {
                    if ($output)
                    {
                        $output->writeln("<error>Canceling order #{$order->getIncrementId()} for payment intent {$paymentIntent->id} failed with exception: {$e->getMessage()}</error>");
                    }
                }
            }
            else
            {
                if ($output)
                {
                    $output->writeln("<error>Cannot cancel order #{$order->getIncrementId()} for payment intent {$paymentIntent->id}</error>");
                }
            }
        }
    }

    protected function getHelper()
    {
        if (!isset($this->helper))
        {
            $this->helper = $this->helperFactory->create();
        }

        return $this->helper;
    }
}
