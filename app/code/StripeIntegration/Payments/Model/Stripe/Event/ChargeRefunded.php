<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class ChargeRefunded extends \StripeIntegration\Payments\Model\Stripe\Event
{
    protected $creditmemoHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Creditmemo $creditmemoHelper,
        \StripeIntegration\Payments\Helper\RequestCache $requestCache,
        \StripeIntegration\Payments\Helper\Compare $compare
    )
    {
        $this->creditmemoHelper = $creditmemoHelper;

        parent::__construct($config, $helper, $dataHelper, $subscriptionsHelper, $webhooksHelper, $requestCache, $compare);
    }
    public function process($arrEvent, $object)
    {
        if ($this->webhooksHelper->wasRefundedFromAdmin($object))
            return;

        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        $result = $this->creditmemoHelper->refundFromStripeDashboard($order, $object);
    }
}