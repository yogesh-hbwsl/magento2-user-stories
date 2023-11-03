<?php

namespace StripeIntegration\Payments\Model\ResourceModel\Coupon;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\Coupon', 'StripeIntegration\Payments\Model\ResourceModel\Coupon');
    }

    public function getByRuleId($ruleId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('rule_id', ['eq' => $ruleId]);

        if (!$collection->getSize())
            return null;
        else
            $coupon = $collection->getFirstItem();

        return $coupon;
    }
}
