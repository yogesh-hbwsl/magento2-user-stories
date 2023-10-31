<?php

namespace Yogesh\Mod1\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Message extends Template
{
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function sayWelcome()
    {
        return "Welcome Message";
    }
}
