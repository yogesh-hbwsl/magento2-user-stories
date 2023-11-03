<?php

namespace StripeIntegration\Payments\Model\ResourceModel\Multishipping\Quote;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\Multishipping\Quote', 'StripeIntegration\Payments\Model\ResourceModel\Multishipping\Quote');
    }

    public function getUncaptured($minAgeHours = 6 * 24, $maxAgeHours = 7 * 24)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $minAge = date("Y-m-d H:i:s", time() - ($minAgeHours * 60 * 60));
        $maxAge = date("Y-m-d H:i:s", time() - ($maxAgeHours * 60 * 60));

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('manual_capture', ['eq' => true])
                    ->addFieldToFilter('captured', ['eq' => false])
                    ->addFieldToFilter('payment_intent_id', ['notnull' => true])
                    ->addFieldToFilter('created_at', ['gteq' => $maxAge])
                    ->addFieldToFilter('created_at', ['lteq' => $minAge]);

        return $collection;
    }
}
