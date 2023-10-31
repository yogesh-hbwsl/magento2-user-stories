<?php

namespace Yogesh\Mod1\Block;

use Magento\Framework\View\Element\Template\Context;

class AfterHtml extends \Magento\Framework\View\Element\Template
{
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function _toHtml()
    {
        return "<div>Hello from toHtml</div>";
    }

    protected function _afterToHtml($html)
    {
        return "<div>After HTML Rendering: $html</div>";
    }
}
