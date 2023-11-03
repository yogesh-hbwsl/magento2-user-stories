<?php

namespace StripeIntegration\Payments\Model\ResourceModel\StripeCustomer;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'id';

    /**
     * Define resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\StripeCustomer', 'StripeIntegration\Payments\Model\ResourceModel\StripeCustomer');
    }

    public function getByCustomerId($customerId, $pk)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('customer_id', ['eq' => $customerId])
                    ->addFieldToFilter(['pk', 'pk'], [$pk, ["null" => true]])
                    ->setOrder('pk','DESC');

        if (!$collection->getSize())
            return null;
        else
        {
            /** @var \StripeIntegration\Payments\Model\StripeCustomer $customer */
            $customer = $collection->getFirstItem();
        }

        if (!$customer->getPk())
            $customer->setPk($pk)->save();

        return $customer;
    }

    public function getBySessionId($sessionId, $pk)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('session_id', ['eq' => $sessionId])
                    ->addFieldToFilter(['pk', 'pk'], [$pk, ["null" => true]])
                    ->setOrder('pk','DESC');

        if (!$collection->getSize())
            return null;
        else
        {
            /** @var \StripeIntegration\Payments\Model\StripeCustomer $customer */
            $customer = $collection->getFirstItem();
        }

        if (!$customer->getPk())
            $customer->setPk($pk)->save();

        return $customer;
    }

    public function getByStripeCustomerId($stripeCustomerId)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('stripe_id', ['eq' => $stripeCustomerId])
                    ->setOrder('pk','DESC');

        if (!$collection->getSize())
            return null;
        else
            $customer = $collection->getFirstItem();

        return $customer;
    }

    public function getByStripeCustomerIdAndPk($stripeCustomerId, $pk)
    {
        $this->clear()->getSelect()->reset(\Magento\Framework\DB\Select::WHERE);

        $collection = $this->addFieldToSelect('*')
                    ->addFieldToFilter('stripe_id', ['eq' => $stripeCustomerId])
                    ->addFieldToFilter(['pk', 'pk'], [$pk, ["null" => true]])
                    ->setOrder('pk','DESC');

        if (!$collection->getSize())
            return null;
        else
            $customer = $collection->getFirstItem();

        return $customer;
    }
}
