<?php

namespace Yogesh\Mod1\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Yogesh\Mod1\Model\ResourceModel\Employee\Collection;

class EmployeeData extends Template
{
    protected $_collection;
    public function __construct(Context $context, Collection $collection)
    {
        $this->_collection = $collection;
        parent::__construct($context);
    }

    public function getEmployeeData()
    {
        return $this->_collection->getItems();
    }
}
