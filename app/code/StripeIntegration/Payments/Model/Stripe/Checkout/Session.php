<?php

// Class representing a Stripe Checkout Session object

namespace StripeIntegration\Payments\Model\Stripe\Checkout;

use StripeIntegration\Payments\Model\Stripe\StripeObject;

class Session extends StripeObject
{
    public $expandParams = ['payment_intent'];
    protected $objectSpace = 'checkout.sessions';

    public function fromParams($params)
    {
        $this->createObject($params);
        return $this;
    }
}