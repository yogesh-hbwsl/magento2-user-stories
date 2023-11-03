<?php

namespace StripeIntegration\Payments\Model\ResourceModel\PaymentElement;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\PaymentElement', 'StripeIntegration\Payments\Model\ResourceModel\PaymentElement');
    }

    public function deleteOlderThan($hours)
    {
        if (!is_numeric($hours))
            return;

        $createdAt = date("Y-m-d H:i:s", time() - ($hours * 60 * 60));

        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('order_increment_id', ['null' => true])
                    ->addFieldToFilter('created_at', ['lteq' => $createdAt]);

        $collection->walk('delete');
    }
}
