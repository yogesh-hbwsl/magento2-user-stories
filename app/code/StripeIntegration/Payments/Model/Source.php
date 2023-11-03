<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception;

class Source extends \Magento\Framework\Model\AbstractModel
{
    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\Source');
    }
}
