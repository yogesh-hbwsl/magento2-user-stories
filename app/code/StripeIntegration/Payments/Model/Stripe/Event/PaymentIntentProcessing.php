<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class PaymentIntentProcessing extends \StripeIntegration\Payments\Model\Stripe\Event
{
    public function process($arrEvent, $object)
    {
        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        if (!$order->getEmailSent())
        {
            $this->helper->sendNewOrderEmailFor($order);
        }
    }
}