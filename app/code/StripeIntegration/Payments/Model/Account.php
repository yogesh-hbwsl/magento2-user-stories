<?php

namespace StripeIntegration\Payments\Model;

class Account extends \Magento\Framework\Model\AbstractModel
{
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\Account');
    }

    public function fromStripeObject(\Stripe\Account $account)
    {
        if (empty($account->id))
            throw new \Exception("Invalid Stripe Account");

        $this->load($account->id, 'account_id');

        $this->setAccountId($account->id);
        $this->setDefaultCurrency($account->default_currency);
        $this->setCountry($account->country);

        return $this;
    }

    public function isValid()
    {
        return !!$this->getIsValid();
    }

    public function needsRefresh()
    {
        $oneWeekAgo = strtotime('-1 week');
        $updatedAt = strtotime($this->getUpdatedAt());
        return $updatedAt < $oneWeekAgo;
    }
}
