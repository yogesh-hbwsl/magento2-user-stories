<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class SourceChargeable extends \StripeIntegration\Payments\Model\Stripe\Event
{
    public function process($arrEvent, $object)
    {
        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        $this->webhooksHelper->charge($order, $object);
    }
}