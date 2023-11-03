<?php

namespace StripeIntegration\Payments\Model;

class SubscriptionReactivation extends \Magento\Framework\Model\AbstractModel
{
    /**
     * Initialise resource model
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\SubscriptionReactivation::class);
    }
}