<?php

namespace StripeIntegration\Payments\Model\ResourceModel\Invoice;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\Invoice', 'StripeIntegration\Payments\Model\ResourceModel\Invoice');
    }
}
