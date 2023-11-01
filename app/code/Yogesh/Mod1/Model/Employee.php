<?php

namespace Yogesh\Mod1\Model;

use Magento\Framework\Model\AbstractModel;

class Employee extends AbstractModel
{
    protected function _construct()
    {
        $this->_init('Yogesh\Mod1\Model\ResourceModel\Employee');
    }
}
