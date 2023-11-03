<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Exception\MissingOrderException;

class SetupIntentSucceeded extends \StripeIntegration\Payments\Model\Stripe\Event
{
    protected $paymentElementFactory;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Model\PaymentElementFactory $paymentElementFactory,
        \StripeIntegration\Payments\Helper\RequestCache $requestCache,
        \StripeIntegration\Payments\Helper\Compare $compare
    )
    {
        $this->paymentElementFactory = $paymentElementFactory;

        parent::__construct($config, $helper, $dataHelper, $subscriptionsHelper, $webhooksHelper, $requestCache, $compare);
    }
    public function process($arrEvent, $object)
    {
        try
        {
            $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
        }
        catch (MissingOrderException $e)
        {
            // We get here when the customer adds a new payment method from the customer account section.
            return;
        }

        // In all other cases, process trial subscription orders for which no charge.succeeded event will be received
        $paymentElement = $this->paymentElementFactory->create()->load($object['id'], 'setup_intent_id');
        if (!$paymentElement->getId())
            return;

        if (!$paymentElement->getSubscriptionId())
            return;

        $subscription = $this->config->getStripeClient()->subscriptions->retrieve($paymentElement->getSubscriptionId());

        $updateData = [];

        if (empty($subscription->metadata->{"Order #"}))
        {
            // With PaymentElement subscriptions, the subscription object is created before the order is placed,
            // and thus it does not have the order number at creation time.
            $updateData["metadata"] = ["Order #" => $order->getIncrementId()];
        }

        if (!empty($object['payment_method']))
        {
            $updateData['default_payment_method'] = $object['payment_method'];
        }

        if (!empty($updateData))
        {
            $subscription = $this->config->getStripeClient()->subscriptions->update($subscription->id, $updateData);
        }

        $this->webhooksHelper->processTrialingSubscriptionOrder($order, $subscription);
    }
}