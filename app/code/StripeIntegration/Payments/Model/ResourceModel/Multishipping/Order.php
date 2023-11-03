<?php

namespace StripeIntegration\Payments\Model\ResourceModel\Multishipping;

class Order extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    protected function _construct()
    {
        $this->_init('stripe_multishipping_orders', 'id');
    }
}
