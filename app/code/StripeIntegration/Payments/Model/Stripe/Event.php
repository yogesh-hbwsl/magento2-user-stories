<?php

namespace StripeIntegration\Payments\Model\Stripe;

class Event extends StripeObject
{
    protected $objectSpace = 'events';

    protected $webhooksHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\RequestCache $requestCache,
        \StripeIntegration\Payments\Helper\Compare $compare
    )
    {
        $this->webhooksHelper = $webhooksHelper;

        parent::__construct($config, $helper, $dataHelper, $subscriptionsHelper, $requestCache, $compare);
    }
}