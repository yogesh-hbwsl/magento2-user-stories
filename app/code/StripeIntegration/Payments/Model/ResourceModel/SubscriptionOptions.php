<?php

namespace StripeIntegration\Payments\Model\ResourceModel;

class SubscriptionOptions extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    protected $_isPkAutoIncrement = false;

    protected function _construct()
    {
        $this->_init('stripe_subscription_options', 'product_id');
    }
}
