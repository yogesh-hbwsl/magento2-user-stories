<?php

namespace StripeIntegration\Payments\Model\ResourceModel\Multishipping;

class Quote extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    protected function _construct()
    {
        $this->_init('stripe_multishipping_quotes', 'id');
    }
}
