<?php

namespace StripeIntegration\Payments\Model\ResourceModel\SubscriptionReactivation;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\SubscriptionReactivation', 'StripeIntegration\Payments\Model\ResourceModel\SubscriptionReactivation');
    }

    public function getByOrderIncrementId($orderIncrementId, $maxAge = 7 * 24 * 60 * 60)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('order_increment_id', ['eq' => $orderIncrementId])
                    ->addFieldToFilter('reactivated_at', ['gteq' => date('Y-m-d H:i:s', time() - $maxAge)]);

        return $collection;
    }

    public function deleteByOrderIncrementId($orderIncrementId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('order_increment_id', ['eq' => $orderIncrementId]);

        foreach ($collection as $item)
            $item->delete();

        return $collection;
    }
}
