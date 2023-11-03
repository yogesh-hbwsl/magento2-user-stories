<?php

namespace StripeIntegration\Payments\Model\ResourceModel;

class PaymentIntent extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    protected $_isPkAutoIncrement = false;

    protected function _construct()
    {
        $this->_init('stripe_payment_intents', 'pi_id');
    }
}
