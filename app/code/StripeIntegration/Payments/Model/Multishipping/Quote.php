<?php

namespace StripeIntegration\Payments\Model\Multishipping;

class Quote extends \Magento\Framework\Model\AbstractModel
{
    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\Multishipping\Quote');
    }
}
