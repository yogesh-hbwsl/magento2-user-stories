<?php

namespace StripeIntegration\Payments\Model\Stripe;

class PaymentMethod extends StripeObject
{
    protected $objectSpace = 'paymentMethods';

    public function fromPaymentMethodId($id)
    {
        if (!empty($this->object->id) && $this->object->id == $id)
        {
            return $this;
        }

        $this->load($id);
        return $this;
    }

    public function getCustomerId()
    {
        if (empty($this->object->customer))
        {
            return null;
        }

        if (!empty($this->object->customer->id))
        {
            return $this->object->customer->id;
        }

        return $this->object->customer;
    }
}