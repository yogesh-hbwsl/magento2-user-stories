<?php

namespace StripeIntegration\Payments\Model\ResourceModel\Source;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\Source', 'StripeIntegration\Payments\Model\ResourceModel\Source');
    }

    public function getSourcesById($sourceId)
    {
        $collection = $this
            ->addFieldToSelect('*')
            ->addFieldToFilter('source_id', ['eq' => $sourceId]);

        return $collection;
    }
}
