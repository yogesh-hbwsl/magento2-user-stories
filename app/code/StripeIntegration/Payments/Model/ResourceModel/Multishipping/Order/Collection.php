<?php

namespace StripeIntegration\Payments\Model\ResourceModel\Multishipping\Order;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\Multishipping\Order', 'StripeIntegration\Payments\Model\ResourceModel\Multishipping\Order');
    }

    public function getByQuoteId($quoteId)
    {
        if (empty($quoteId) || !is_numeric($quoteId))
            return [];

        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('quote_id', ['eq' => $quoteId]);

        return $collection;
    }

}
