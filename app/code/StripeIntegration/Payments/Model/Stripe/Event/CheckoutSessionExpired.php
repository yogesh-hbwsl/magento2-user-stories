<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class CheckoutSessionExpired extends \StripeIntegration\Payments\Model\Stripe\Event
{
    public function process($arrEvent, $object)
    {
        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        $this->webhooksHelper->addOrderComment($order, __("Stripe Checkout session has expired without a payment."));

        if ($this->helper->isPendingCheckoutOrder($order))
            $this->helper->cancelOrCloseOrder($order);
    }
}