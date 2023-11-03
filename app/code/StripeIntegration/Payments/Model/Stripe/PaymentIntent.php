<?php

namespace StripeIntegration\Payments\Model\Stripe;

class PaymentIntent extends StripeObject
{
    protected $objectSpace = 'paymentIntents';

    public function fromPaymentIntentId($id, $expandParams = [])
    {
        $id = $this->helper->cleanToken($id);

        if (!empty($this->object->id) && $this->object->id == $id)
        {
            return $this;
        }

        $this->expandParams = $expandParams;
        $this->load($id);
        return $this;
    }

    public function fromObject(\Stripe\PaymentIntent $paymentIntent)
    {
        $this->object = $paymentIntent;
        return $this;
    }
}