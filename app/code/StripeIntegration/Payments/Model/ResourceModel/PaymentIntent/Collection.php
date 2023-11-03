<?php

namespace StripeIntegration\Payments\Model\ResourceModel\PaymentIntent;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'pi_id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\PaymentIntent', 'StripeIntegration\Payments\Model\ResourceModel\PaymentIntent');
    }

    public function deleteForQuoteId($quoteId)
    {
        if (empty($quoteId) || !is_numeric($quoteId))
            return;

        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('quote_id', ['eq' => $quoteId]);

        $collection->walk('delete');
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
