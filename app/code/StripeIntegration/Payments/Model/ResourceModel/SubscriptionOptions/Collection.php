<?php

namespace StripeIntegration\Payments\Model\ResourceModel\SubscriptionOptions;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'product_id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\SubscriptionOptions', 'StripeIntegration\Payments\Model\ResourceModel\SubscriptionOptions');
    }
}
