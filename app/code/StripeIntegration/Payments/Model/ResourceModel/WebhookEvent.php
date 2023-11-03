<?php

namespace StripeIntegration\Payments\Model\ResourceModel;

class WebhookEvent extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    protected function _construct()
    {
        $this->_init('stripe_webhook_events', 'id');
    }
}
