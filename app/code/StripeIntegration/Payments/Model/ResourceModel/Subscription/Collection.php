<?php

namespace StripeIntegration\Payments\Model\ResourceModel\Subscription;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\Subscription', 'StripeIntegration\Payments\Model\ResourceModel\Subscription');
    }

    public function getByOrderIncrementId($incrementId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('order_increment_id', ['eq' => $incrementId])
                    ->setOrder('created_at','ASC');

        return $collection;
    }

    public function getBySubscriptionId($subscriptionId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('subscription_id', ['eq' => $subscriptionId])
                    ->setOrder('created_at','DESC');

        if ($collection->getSize() > 0)
            return $collection->getFirstItem();

        return null;
    }

    public function getBySubscriptionStatus($status)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
            ->addFieldToFilter('status', ['eq' => $status])
            ->setOrder('created_at','DESC');

        if ($collection->getSize() > 0)
            return $collection->getColumnValues('subscription_id');

        return [];
    }
}
