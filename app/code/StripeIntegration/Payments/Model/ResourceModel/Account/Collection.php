<?php

namespace StripeIntegration\Payments\Model\ResourceModel\Account;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\Account', 'StripeIntegration\Payments\Model\ResourceModel\Account');
    }

    public function getByAccountId($accountId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('account_id', ['eq' => $accountId]);

        if (!$collection->getSize())
            return null;
        else
            $account = $collection->getFirstItem();

        return $account;
    }

    public function findByKeys($publishableKey, $encryptedSecretKey)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('publishable_key', ['eq' => $publishableKey])
                    ->addFieldToFilter('secret_key', ['eq' => $encryptedSecretKey]);

        return $collection->getFirstItem();
    }
}
