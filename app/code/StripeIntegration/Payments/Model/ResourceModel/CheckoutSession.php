<?php

namespace StripeIntegration\Payments\Model\ResourceModel;

class CheckoutSession extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        $resourcePrefix = null
    ) {
        parent::__construct($context, $resourcePrefix);
    }

    protected function _construct()
    {
        $this->_init('stripe_checkout_sessions', 'id');
    }
}
