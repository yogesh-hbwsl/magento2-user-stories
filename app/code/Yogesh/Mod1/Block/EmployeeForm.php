<?php

namespace Yogesh\Mod1\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class EmployeeForm extends Template
{
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function getFormAction()
    {
        return $this->getUrl('mod1/employeeform/');
    }
}
