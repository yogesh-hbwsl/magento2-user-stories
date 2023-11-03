<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Exception\WebhookException;

class InvoicePaymentSucceeded extends \StripeIntegration\Payments\Model\Stripe\Event
{
    protected $subscription = null;
    protected $recurringOrderHelper;
    protected $paymentMethodHelper;
    protected $checkoutSessionHelper;
    protected $creditmemoHelper;
    protected $paymentIntentFactory;
    protected $subscriptionFactory;
    protected $subscriptionReactivationCollection;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\RecurringOrder $recurringOrderHelper,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \StripeIntegration\Payments\Helper\Creditmemo $creditmemoHelper,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentFactory,
        \StripeIntegration\Payments\Model\SubscriptionFactory $subscriptionFactory,
        \StripeIntegration\Payments\Model\ResourceModel\SubscriptionReactivation\Collection $subscriptionReactivationCollection,
        \StripeIntegration\Payments\Helper\RequestCache $requestCache,
        \StripeIntegration\Payments\Helper\Compare $compare
    )
    {
        $this->recurringOrderHelper = $recurringOrderHelper;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->creditmemoHelper = $creditmemoHelper;
        $this->paymentIntentFactory = $paymentIntentFactory;
        $this->subscriptionFactory = $subscriptionFactory;
        $this->subscriptionReactivationCollection = $subscriptionReactivationCollection;

        parent::__construct($config, $helper, $dataHelper, $subscriptionsHelper, $webhooksHelper, $requestCache, $compare);
    }

    public function process($arrEvent, $object)
    {
        try
        {
            $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
        }
        catch (\StripeIntegration\Payments\Exception\SubscriptionUpdatedException $e)
        {
            try
            {
                if ($object['billing_reason'] == "subscription_cycle")
                {
                    return $this->recurringOrderHelper->createFromQuoteId($e->getQuoteId(), $object['id']);
                }
                else /* if ($object['billing_reason'] == "subscription_update") */
                {
                    // At the very first subscription update (prorated or not), do not create a recurring order.
                    return;
                }
            }
            catch (\Exception $e)
            {
                $this->webhooksHelper->sendRecurringOrderFailedEmail($arrEvent, $e);
                throw $e;
            }
        }

        if (empty($order->getPayment()))
            throw new WebhookException("Order #%1 does not have any associated payment details.", $order->getIncrementId());

        $paymentMethod = $order->getPayment()->getMethod();
        $invoiceId = $object['id'];
        $invoiceParams = [
            'expand' => [
                'lines.data.price.product',
                'subscription',
                'payment_intent'
            ]
        ];
        $invoice = $this->config->getStripeClient()->invoices->retrieve($invoiceId, $invoiceParams);

        if (isset($invoice->payment_intent->charges->data[0]) && isset($invoice->payment_intent->charges->data[0]->outcome)) {
            $this->dataHelper->setRiskDataToOrder($invoice->payment_intent, $order);
        }

        //Insert Stripe payment method
        $this->paymentMethodHelper->insertPaymentMethods($invoice->payment_intent, $order, false, true);

        $this->load($arrEvent['id']);

        if ($this->isSubscriptionUpdate($object) && !$this->isPhasedSubscriptionUpdate($order))
        {
            // The event will arrive before the order is saved to the database. $order is likely the original order before
            // the subscription was updated. So don't change any order state here. Use an after order saved observer instead.
            return;
        }

        $isNewSubscriptionOrder = (!empty($object["billing_reason"]) && $object["billing_reason"] == "subscription_create");
        $isSubscriptionReactivation = $this->isSubscriptionReactivation($order, $object);

        switch ($paymentMethod)
        {
            case 'stripe_payments':
            case 'stripe_payments_express':

                $subscriptionId = $invoice->subscription->id;
                $subscriptionModel = $this->subscriptionFactory->create()->load($subscriptionId, "subscription_id");
                $subscriptionModel->initFrom($invoice->subscription, $order)->save();

                $updateParams = [];
                if (empty($invoice->subscription->default_payment_method) && !empty($invoice->payment_intent->payment_method))
                    $updateParams["default_payment_method"] = $invoice->payment_intent->payment_method;

                if (empty($invoice->subscription->metadata->{"Order #"}))
                    $updateParams["metadata"] = ["Order #" => $order->getIncrementId()];

                if (!empty($updateParams))
                    $this->config->getStripeClient()->subscriptions->update($subscriptionId, $updateParams);

                if (!empty($invoice->payment_intent->id))
                {
                    // The subscription description is not normally passed to the underlying payment intent
                    $this->config->getStripeClient()->paymentIntents->update($invoice->payment_intent->id, [
                        "description" => $this->helper->getOrderDescription($order)
                    ]);
                }

                if (!$isNewSubscriptionOrder || $isSubscriptionReactivation)
                {
                    try
                    {
                        // This is a recurring payment, so create a brand new order based on the original one
                        $this->recurringOrderHelper->createFromInvoiceId($invoiceId);
                    }
                    catch (\Exception $e)
                    {
                        $this->webhooksHelper->sendRecurringOrderFailedEmail($arrEvent, $e);
                        throw $e;
                    }
                }

                break;

            case 'stripe_payments_checkout':

                if ($isNewSubscriptionOrder)
                {
                    if (!empty($invoice->payment_intent))
                    {
                        // With Stripe Checkout, the Payment Intent description and metadata can be set only
                        // after the payment intent is confirmed and the subscription is created.
                        $quote = $this->helper->loadQuoteById($order->getQuoteId());
                        $params = $this->paymentIntentFactory->create()->getParamsFrom($quote, $order, $invoice->payment_intent->payment_method);
                        $updateParams = $this->checkoutSessionHelper->getPaymentIntentUpdateParams($params, $invoice->payment_intent, $filter = ["description", "metadata"]);
                        $this->config->getStripeClient()->paymentIntents->update($invoice->payment_intent->id, $updateParams);
                        $invoice = $this->config->getStripeClient()->invoices->retrieve($invoiceId, $invoiceParams);
                    }
                    else if ($this->helper->hasOnlyTrialSubscriptionsIn($order->getAllItems()))
                    {
                        // No charge.succeeded event will arrive, so ready the order for fulfillment here.
                        $order = $this->helper->loadOrderById($order->getId()); // Refresh in case another event is mutating the order
                        if (!$order->getEmailSent())
                        {
                            $this->helper->sendNewOrderEmailFor($order, true);
                        }
                        if ($order->getInvoiceCollection()->getSize() < 1)
                        {
                            $this->helper->invoiceOrder($order, null, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
                        }
                        $this->helper->setProcessingState($order, __("Trial subscription started."));
                        $this->helper->saveOrder($order);
                    }

                    if ($invoice->status == "paid")
                    {
                        $this->creditmemoHelper->refundUnderchargedOrder($order, $invoice->amount_paid, $invoice->currency);
                    }
                }
                else // Is recurring subscription order
                {
                    try
                    {
                        // This is a recurring payment, so create a brand new order based on the original one
                        $this->recurringOrderHelper->createFromSubscriptionItems($invoiceId);
                    }
                    catch (\Exception $e)
                    {
                        $this->webhooksHelper->sendRecurringOrderFailedEmail($arrEvent, $e);
                        throw $e;
                    }
                }

                break;
            case 'stripe_payments_invoice':

                // Save the Risk Score
                if (isset($invoice->payment_intent->charges->data[0]->outcome)) {
                    $this->helper->saveOrder($order);
                }

                break;

            default:
                # code...
                break;
        }

        if ($isSubscriptionReactivation)
        {
            $this->subscriptionReactivationCollection->deleteByOrderIncrementId($order->getIncrementId());
        }
    }

    protected function isSubscriptionUpdate($object)
    {
        if (empty($object['billing_reason']))
            return false;

        return $object['billing_reason'] == 'subscription_update';
    }

    protected function isPhasedSubscriptionUpdate($order)
    {
        if (!$order->getPayment()->getAdditionalInformation("subscription_schedule_id"))
            return false;

        try
        {
            // Get the subscription schedule
            $scheduleId = $order->getPayment()->getAdditionalInformation("subscription_schedule_id");
            $schedule = $this->config->getStripeClient()->subscriptionSchedules->retrieve($scheduleId, []);
        }
        catch (\Exception $e)
        {
            return false;
        }

        // Check if the subscription has just entered a new phase
        if (empty($schedule->current_phase->start_date))
            return false;

        // Check if the start date is within 12 hours. Large diff to compensate for delayed webhook arrival
        $diff = time() - $schedule->current_phase->start_date;
        if ($diff > 43200)
            return false;

        return true;
    }

    protected function isSubscriptionReactivation($order, $object)
    {
        $collection = $this->subscriptionReactivationCollection->getByOrderIncrementId($order->getIncrementId());

        foreach ($collection as $reactivation)
        {
            return true;
        }

        return false;
    }
}
