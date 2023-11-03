<?php

namespace StripeIntegration\Payments\Model\Multishipping;

class Order extends \Magento\Framework\Model\AbstractModel
{
    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\Multishipping\Order');
    }
}
