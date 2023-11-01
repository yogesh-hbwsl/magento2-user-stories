<?php

namespace Yogesh\Mod1\Model\ResourceModel\Employee;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Yogesh\Mod1\Model\Employee as Model;
use Yogesh\Mod1\Model\ResourceModel\Employee as ResourceModel;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
