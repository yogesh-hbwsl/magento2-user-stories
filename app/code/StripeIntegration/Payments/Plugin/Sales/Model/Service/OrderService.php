<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Service;

use Magento\Framework\Exception\LocalizedException;

class OrderService
{
    protected $helper;
    protected $subscriptionsHelper;

    protected $config;
    protected $creditmemoHelper;
    protected $helperFactory;
    protected $quoteHelper;
    protected $subscriptionsFactory;
    protected $webhookEventCollectionFactory;
    protected $paymentMethodHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\GenericFactory $helperFactory,
        \StripeIntegration\Payments\Helper\SubscriptionsFactory $subscriptionsFactory,
        \StripeIntegration\Payments\Helper\Creditmemo $creditmemoHelper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\ResourceModel\WebhookEvent\CollectionFactory $webhookEventCollectionFactory

    ) {
        $this->quoteHelper = $quoteHelper;
        $this->helperFactory = $helperFactory;
        $this->subscriptionsFactory = $subscriptionsFactory;
        $this->creditmemoHelper = $creditmemoHelper;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->config = $config;
        $this->webhookEventCollectionFactory = $webhookEventCollectionFactory;
    }

    public function aroundPlace($subject, \Closure $proceed, $order)
    {
        try
        {
            if (!empty($order) && !empty($order->getQuoteId()))
            {
                $this->quoteHelper->quoteId = $order->getQuoteId();
            }

            $savedOrder = $proceed($order);

            return $this->postProcess($savedOrder);
        }
        catch (\Exception $e)
        {
            $helper = $this->helperFactory->create();
            $msg = $e->getMessage();

            if ($helper->isAuthenticationRequiredMessage($msg))
                throw $e;
            else
                $helper->dieWithError($e->getMessage(), $e);
        }
    }

    public function postProcess($order)
    {
        $helper = $this->getHelper();
        switch ($order->getPayment()->getMethod())
        {
            case "stripe_payments_bank_transfers":
                $this->paymentMethodHelper->savePaymentMethod($order->getId(), "customer_balance", null);
                break;
            case "stripe_payments_invoice":
                $comment = __("A payment is pending for this order.");
                $helper->setOrderState($order, \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT, $comment);
                $helper->saveOrder($order);
                break;
            case "stripe_payments":
            case "stripe_payments_express":

                if ($transactionId = $order->getPayment()->getAdditionalInformation("server_side_transaction_id"))
                {
                    // Process webhook events which have arrived before the order was saved
                    $events = $this->webhookEventCollectionFactory->create()->getEarlyEventsForPaymentIntentId($transactionId, [
                        'charge.succeeded', // Regular orders
                        'invoice.payment_succeeded' // Subscriptions
                    ]);

                    foreach ($events as $eventModel)
                    {
                        try
                        {
                            $eventModel->process($this->config->getStripeClient());
                        }
                        catch (\Exception $e)
                        {
                            $eventModel->refresh()->setLastErrorFromException($e);
                        }
                    }
                }

                if ($order->getPayment()->getAdditionalInformation("is_trial_subscription_setup"))
                {
                    $this->creditmemoHelper->refundUnderchargedOrder($order, $paid = 0, $currency = strtolower($order->getOrderCurrencyCode()));
                }

                if ($order->getPayment()->getAdditionalInformation("is_subscription_update"))
                {
                    if ($order->getPayment()->getIsTransactionPending())
                    {
                        // Prorated downgrade, no price change, or upgrade with credit balance
                        $this->getHelper()->cancelOrCloseOrder($order);
                    }
                    else if ($order->getPayment()->getTransactionId())
                    {
                        // Prorated upgrade
                        $this->getHelper()->invoiceOrder($order, $order->getPayment()->getTransactionId(), \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, null, true);
                        $amountPaid = $order->getPayment()->getAdditionalInformation("stripe_invoice_amount_paid");
                        $currency = $order->getPayment()->getAdditionalInformation("stripe_invoice_currency");
                        $this->creditmemoHelper->refundUnderchargedOrder($order, $amountPaid, $currency, true);
                    }
                }

                break;
            default:
                break;
        }

        return $order;
    }

    protected function getHelper()
    {
        if (!isset($this->helper))
        {
            $this->helper = $this->helperFactory->create();
        }

        return $this->helper;
    }

    protected function getSubscriptionsHelper()
    {
        if (!isset($this->subscriptionsHelper))
        {
            $this->subscriptionsHelper = $this->subscriptionsFactory->create();
        }

        return $this->subscriptionsHelper;
    }
}
