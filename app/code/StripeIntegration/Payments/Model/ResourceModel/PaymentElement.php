<?php

namespace StripeIntegration\Payments\Model\ResourceModel;

class PaymentElement extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    protected function _construct()
    {
        $this->_init('stripe_payment_elements', 'id');
    }
}
