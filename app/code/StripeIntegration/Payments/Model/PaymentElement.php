<?php

namespace StripeIntegration\Payments\Model;

use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Exception\SCANeededException;

class PaymentElement extends \Magento\Framework\Model\AbstractModel
{
    protected $paymentIntent = null;
    protected $setupIntent = null;
    protected $subscription = null;
    protected $clientSecrets = [];

    private $subscriptionsHelper;
    private $addressHelper;
    private $cache;
    private $quoteFactory;
    private $quoteRepository;
    private $addressFactory;
    private $checkoutSessionHelper;
    private $paymentMethodHelper;
    private $paymentIntentHelper;
    private $session;
    private $checkoutHelper;
    private $customer;
    private $compare;
    private $paymentIntentModelFactory;
    private $dataHelper;
    private $helper;
    private $config;
    private $stripePaymentIntent;

    public function __construct(
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentModelFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntent $stripePaymentIntent,
        \Magento\Customer\Model\AddressFactory $addressFactory,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Framework\Session\Generic $session,
        \Magento\Checkout\Helper\Data $checkoutHelper,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
        )
    {
        $this->dataHelper = $dataHelper;
        $this->helper = $helper;
        $this->compare = $compare;
        $this->paymentIntentModelFactory = $paymentIntentModelFactory;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->addressHelper = $addressHelper;
        $this->cache = $context->getCacheManager();
        $this->config = $config;
        $this->stripePaymentIntent = $stripePaymentIntent;
        $this->customer = $helper->getCustomerModel();
        $this->quoteFactory = $quoteFactory;
        $this->quoteRepository = $quoteRepository;
        $this->addressFactory = $addressFactory;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->session = $session;
        $this->checkoutHelper = $checkoutHelper;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\PaymentElement');
    }

    public function updateFromOrder($order, $paymentMethodId = null)
    {
        if (empty($order))
            throw new \Exception("No order specified.");

        $quote = $this->helper->loadQuoteById($order->getQuoteId());

        $this->load($quote->getId(), 'quote_id');

        if ($this->getOrderIncrementId() && $this->getOrderIncrementId() != $order->getIncrementId())
        {
            // Check if this is a duplicate order placement. The old order should have normally been canceled if the cart changed.
            $oldOrder = $this->helper->loadOrderByIncrementId($this->getOrderIncrementId());
            if ($oldOrder && $oldOrder->getState() != "canceled" && !$this->helper->isMultiShipping())
            {
                // The case where the old order was not canceled is when the payment failed and the cart contents changed
                $this->setOrderIncrementId($order->getIncrementId())->save();
                if ($order->getGrandTotal() == $oldOrder->getGrandTotal() && $order->getOrderCurrencyCode() == $oldOrder->getOrderCurrencyCode())
                {
                    $comment = __("The customer details have changed, or a checkout error occurred. The order is canceled because a new one will be placed (#%1) with the new details.", $order->getIncrementId());
                }
                else
                {
                    $comment = __("The cart contents have changed. The order is canceled because a new one will be placed (#%1) with the new details.", $order->getIncrementId());
                }

                $oldOrder->addStatusToHistory($status = false, $comment, $isCustomerNotified = false);
                $this->helper->removeTransactions($oldOrder);
                $this->helper->cancelOrCloseOrder($oldOrder, true);
            }
        }

        // Update any existing subscriptions
        $paymentIntentModel = $this->paymentIntentModelFactory->create();
        $params = $paymentIntentModel->getParamsFrom($quote, $order, $paymentMethodId);
        $subscription = $this->subscriptionsHelper->updateSubscriptionFromOrder($order, $this->getSubscriptionId(), $params);
        if (!empty($subscription->id))
        {
            $this->updateFromSubscription($subscription);
            $order->getPayment()->setAdditionalInformation("subscription_id", $subscription->id);
            $this->subscription = $subscription;
        }

        $paymentIntent = $setupIntent = null;

        if (!empty($subscription->latest_invoice->payment_intent->id))
        {
            $paymentIntent = $subscription->latest_invoice->payment_intent;
        }
        else if (!empty($subscription->pending_setup_intent->id))
        {
            $setupIntent = $subscription->pending_setup_intent;
        }
        else
        {
            // Update any existing payment intents
            $paymentIntent = $paymentIntentModel->loadFromCache($params, $quote, $order, $this->getPaymentIntentId());
            if (!$paymentIntent)
            {
                $this->paymentIntent = null;
                $this->setPaymentIntentId(null);
                $paymentIntent = $paymentIntentModel->create($params, $quote, $order);
            }
        }

        // Upon order placement, a customer is always created in Stripe
        if ($this->customer->getStripeId())
        {
            $this->customer->updateFromOrder($order);
            $params['customer'] = $this->customer->getStripeId();
        }

        if ($paymentIntent)
        {
            $this->setupIntent = null;
            $this->setSetupIntentId(null);
            $this->setPaymentIntentId($paymentIntent->id);

            $this->paymentIntent = $this->updatePaymentIntentFrom($paymentIntent, $params);
        }
        else if ($setupIntent)
        {
            $this->paymentIntent = null;
            $this->setSetupIntentId($setupIntent->id);
            $this->setPaymentIntentId(null);

            $this->setupIntent = $this->updateSetupIntentFrom($setupIntent, $params);
        }

        if (!empty($subscription))
        {
            $this->subscription = $subscription;
            $this->setSubscriptionId($subscription->id);
        }

        $this->setOrderIncrementId($order->getIncrementId());
        $this->setQuoteId($order->getQuoteId());
        $this->save();
    }

    // There are some cases where an error occurred after placing an order, and somehow the quote was
    // recreated, thus losing the reference to the old Pending order. In those cases, we want to search
    // and find those pending orders and manually cancel them before creating a new one using the same
    // payment intent ID. Having 2 orders with the same payment intent ID is very problematic.
    public function cancelInvalidOrders($currentOrder)
    {
        $transactionId = $this->getPaymentIntentId();

        if (!$transactionId || $this->helper->isMultiShipping())
        {
            return;
        }

        $orders = $this->helper->getOrdersByTransactionId($transactionId);
        $comment = __("The cart contents or customer details have changed. The order is canceled because a new one will be placed (#%1) with the new details.", $currentOrder->getIncrementId());

        foreach ($orders as $order)
        {
            if ($order->getIncrementId() == $currentOrder->getIncrementId())
            {
                continue;
            }

            if ($order->getState() == "canceled")
            {
                continue;
            }

            try
            {
                $quote = $this->helper->loadQuoteById($order->getQuoteId());
                if ($this->helper->isMultiShipping($quote))
                {
                    continue;
                }
                $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = false);
                $this->helper->removeTransactions($order);
                $this->helper->cancelOrCloseOrder($order, true);

                if ($currentOrder->getQuoteId() != $order->getQuoteId())
                {
                    $this->paymentIntentModelFactory->create()->destroy($order->getQuoteId());
                }
            }
            catch (\Exception $e)
            {
                $this->helper->logError("Could not cancel invalid order: " . $e->getMessage());
            }
        }
    }

    public function updatePaymentIntentFrom($paymentIntent, $params)
    {
        $updateParams = $this->getFilteredParamsForUpdate($paymentIntent, $params);

        if ($this->compare->isDifferent($paymentIntent, $updateParams))
            return $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, $updateParams);

        return $paymentIntent;
    }

    public function updateSetupIntentFrom($setupIntent, $params)
    {
        $updateParams = $this->getFilteredParamsForUpdate($setupIntent, $params);

        if ($this->compare->isDifferent($setupIntent, $updateParams))
            return $this->config->getStripeClient()->setupIntents->update($setupIntent->id, $updateParams);

        return $setupIntent;
    }

    protected function getFilteredParamsForUpdate($object, $params)
    {
        $updateParams = $this->paymentIntentHelper->getFilteredParamsForUpdate($params, $object);

        if ($this->getSetupIntent() || $this->getSubscription())
        {
            unset($updateParams['amount']); // If we have a subscription, the amount will be incorrect here: Order total - Subscriptions total
        }

        return ($updateParams ? $updateParams : []);
    }

    public function getSavedPaymentMethods($quoteId = null)
    {
        $customer = $this->helper->getCustomerModel();

        if (!$customer->getStripeId() || !$this->helper->isCustomerLoggedIn())
            return [];

        $quote = $this->helper->getQuote($quoteId);
        if (!$quote)
            return [];

        if (!$quoteId)
            $quoteId = $quote->getId();

        $filteredMethods = $this->paymentMethodHelper->getFilteredPaymentMethodTypes();

        $savedMethods = $customer->getSavedPaymentMethods($filteredMethods, true);

        return $savedMethods;
    }


    public function isOrderPlaced()
    {
        if (!$this->getOrderIncrementId())
        {
            $quote = $this->helper->getQuote();

            if (!$quote || !$quote->getId())
            {
                return false;
            }

            $this->load($quote->getId(), 'quote_id');
        }

        return (bool)($this->getOrderIncrementId());
    }

    public function getSubscription(): ?\Stripe\Subscription
    {
        return $this->subscription;
    }

    public function getPaymentIntent(): ?\Stripe\PaymentIntent
    {
        return $this->paymentIntent;
    }

    public function getSetupIntent(): ?\Stripe\SetupIntent
    {
        return $this->setupIntent;
    }

    public function fromQuoteId($quoteId)
    {
        $this->load($quoteId, "quote_id");

        if ($this->getPaymentIntentId())
        {
            try
            {
                $paymentIntent = $this->stripePaymentIntent->fromPaymentIntentId($this->getPaymentIntentId())->getStripeObject();
                $this->paymentIntent = $paymentIntent;
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if ($e->getHttpStatus() == 404)
                {
                    $this->paymentIntent = null;
                    $this->setPaymentIntentId(null)->save();
                }
                else
                {
                    throw $e;
                }
            }
        }

        if ($this->getSetupIntentId())
        {
            try
            {
                $this->setupIntent = $this->config->getStripeClient()->setupIntents->retrieve($this->getSetupIntentId(), []);
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if ($e->getHttpStatus() == 404)
                {
                    $this->paymentIntent = null;
                    $this->setSetupIntentId(null)->save();
                }
                else
                {
                    throw $e;
                }
            }
        }

        if ($this->getSubscriptionId())
        {
            try
            {
                $this->subscription = $this->config->getStripeClient()->subscriptions->retrieve($this->getSubscriptionId(), []);
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if ($e->getHttpStatus() == 404)
                {
                    $this->paymentIntent = null;
                    $this->setSubscriptionId(null)->save();
                }
                else
                {
                    throw $e;
                }
            }
        }

        return $this;
    }

    public function isTrialSubscription()
    {
        if ($this->getPaymentIntentId() || $this->getSetupIntentId() || !$this->getSubscription())
        {
            return false;
        }

        return ($this->getSubscription()->status == "trialing");
    }

    public function confirm($order)
    {
        $paymentIntentModel = $this->paymentIntentModelFactory->create();

        if (!$this->getQuoteId())
        {
            throw new \Exception("Not initialized");
        }

        if ($confirmationObject = $this->getPaymentIntent())
        {
            if (empty($confirmationObject->metadata->{'Order #'}))
            {
                $confirmationObject = $this->paymentIntent = $this->config->getStripeClient()->paymentIntents->update($confirmationObject->id, [
                    'description' => $this->helper->getOrderDescription($order),
                    'metadata' => $this->config->getMetadata($order)
                ]);
            }

            // Wallet button 3DS confirms the PI on the client side and retries order placement
            if ($this->paymentIntentHelper->isSuccessful($confirmationObject) || $this->paymentIntentHelper->requiresOfflineAction($confirmationObject))
            {
                // We get here in 2 cases:
                // a) A checkout crash in a sales_order_place_after observer may have forced the customer to place the order twice
                // b) Non-PaymentElement 3D Secure authentications or handleNextActions, which were done on the client side, i.e. GraphQL, Wallet button etc
                return $confirmationObject;
            }

            $confirmParams = $paymentIntentModel->getConfirmParams($order, $confirmationObject, true);

            try
            {
                $result = $this->config->getStripeClient()->paymentIntents->confirm($confirmationObject->id, $confirmParams);
                $this->paymentIntent = $result;
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if (!$this->dataHelper->isMOTOError($e->getError()))
                    throw $e;

                $this->cache->save($value = "1", $key = "no_moto_gate", ["stripe_payments"], $lifetime = 6 * 60 * 60);
                unset($confirmParams['payment_method_options']['card']['moto']);
                $result = $this->config->getStripeClient()->paymentIntents->confirm($confirmationObject->id, $confirmParams);
                $this->paymentIntent = $result;
            }
        }
        else if ($confirmationObject = $this->getSetupIntent())
        {
            if (empty($confirmationObject->metadata->{'Order #'}))
            {
                $confirmationObject = $this->setupIntent = $this->config->getStripeClient()->setupIntents->update($confirmationObject->id, [
                    'description' => $this->helper->getOrderDescription($order),
                    'metadata' => $this->config->getMetadata($order)
                ]);
            }

            // Wallet button 3DS confirms the SI on the client side and retries order placement
            if ($confirmationObject->status == "succeeded")
            {
                return $confirmationObject;
            }

            $confirmParams = $paymentIntentModel->getConfirmParams($order, $confirmationObject, true);
            $confirmParams = $this->dataHelper->convertToSetupIntentConfirmParams($confirmParams);

            try
            {
                $result = $this->config->getStripeClient()->setupIntents->confirm($confirmationObject->id, $confirmParams);
                $this->setupIntent = $result;
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if (!$this->dataHelper->isMOTOError($e->getError()))
                    throw $e;

                $this->cache->save($value = "1", $key = "no_moto_gate", ["stripe_payments"], $lifetime = 6 * 60 * 60);
                unset($confirmParams['payment_method_options']['card']['moto']);
                $result = $this->config->getStripeClient()->setupIntents->confirm($confirmationObject->id, $confirmParams);
                $this->setupIntent = $result;
            }
        }
        else if ($confirmationObject = $this->getSubscription())
        {
            if (empty($confirmationObject->metadata->{'Order #'}))
            {
                $confirmationObject = $this->subscription = $this->config->getStripeClient()->subscriptions->update($confirmationObject->id, [
                    'description' => $this->helper->getOrderDescription($order),
                    'metadata' => $this->config->getMetadata($order)
                ]);
            }

            if ($confirmationObject->status == "trialing")
            {
                // Case where the customer is buying a trial subscription with a saved payment method
                // that has already been 3DS authenticated in a previous subscription order.
                return $confirmationObject;
            }
            else if ($confirmationObject->status == "active")
            {
                /** @var \Stripe\Subscription $confirmationObject */
                if (!empty($confirmationObject->latest_invoice->amount_due) &&
                    $confirmationObject->latest_invoice->amount_due > 0 &&
                    $confirmationObject->latest_invoice->amount_paid == 0 &&
                    !empty($confirmationObject->default_payment_method))
                {
                    // A subscription is set up with a future start date, and payment is required today.
                    if (empty($confirmationObject->latest_invoice->payment_intent))
                    {
                        try
                        {
                            $invoice = $this->config->getStripeClient()->invoices->pay($confirmationObject->latest_invoice->id, [
                                'expand' => ['payment_intent']
                            ]);
                            return $invoice->payment_intent;
                        }
                        catch (\Exception $e)
                        {
                            // 3DS might be required
                            /** @var \Stripe\Invoice $invoice */
                            $invoice = $this->config->getStripeClient()->invoices->retrieve($confirmationObject->latest_invoice->id, [
                                'expand' => ['payment_intent']
                            ]);
                            if (!empty($invoice->payment_intent->id) && $invoice->payment_intent->status == "requires_action")
                            {
                                $this->paymentIntent = $invoice->payment_intent;
                                return $this->confirm($order);
                            }
                            else
                            {
                                throw $e;
                            }
                        }
                    }
                    else
                    {
                        if (is_string($confirmationObject->latest_invoice->payment_intent))
                        {
                            $this->paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($confirmationObject->latest_invoice->payment_intent);
                        }
                        else
                        {
                            $this->paymentIntent = $confirmationObject->latest_invoice->payment_intent;
                        }
                        return $this->confirm($order);
                    }
                }
                else
                {
                    // A subscription is set up with a future start date, with no payment required today.
                    return $confirmationObject;
                }
            }
            else
            {
                throw new \Exception("Could not set up subscription.");
            }
        }
        else
        {
            throw new \Exception("Could not confirm payment.");
        }

        if ($result && isset($result->charges->data[0])) {
            $this->dataHelper->setRiskDataToOrder($result, $order);
        }

        return $result;
    }

    public function requiresConfirmation()
    {
        if (!$this->paymentIntent && !$this->setupIntent)
        {
            if ($this->getPaymentIntentId())
            {
                $this->paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($this->getPaymentIntentId(), []);
            }
            else if ($this->getSetupIntentId())
            {
                $this->setupIntent = $this->config->getStripeClient()->setupIntents->retrieve($this->getSetupIntentId(), []);
            }
        }

        if ($this->paymentIntent && $this->paymentIntent->status == "requires_confirmation")
        {
            return true;
        }

        if ($this->setupIntent && $this->setupIntent->status == "requires_confirmation")
        {
            return true;
        }

        return false;
    }

    protected function updateFromSubscription(?\Stripe\Subscription $subscription)
    {
        if (empty($subscription->id))
            return;

        $this->setSubscriptionId($subscription->id);

        if (!empty($subscription->latest_invoice->payment_intent->id))
        {
            $this->setSetupIntentId(null);
            $this->setPaymentIntentId($subscription->latest_invoice->payment_intent->id);
            $this->setupIntent = null;
            $this->paymentIntent = $subscription->latest_invoice->payment_intent;
        }
        else if (!empty($subscription->pending_setup_intent->id))
        {
            $this->setSetupIntentId($subscription->pending_setup_intent->id);
            $this->setPaymentIntentId(null);
            $this->setupIntent = $subscription->pending_setup_intent;
            $this->paymentIntent = null;
        }

        $this->save();
    }

    protected function convertToSetupIntentParams($quote, $params)
    {
        $newParams = [];

        foreach ($params as $key => $value)
        {
            switch ($key)
            {
                case 'description':
                case 'customer':
                case 'metadata':
                    $newParams[$key] = $value;
                    break;
                default:
                    break;
            }
        }

        $usage = $this->config->getSetupFutureUsage($quote);
        if ($usage)
            $newParams['usage'] = $usage;

        return $newParams;
    }
}
