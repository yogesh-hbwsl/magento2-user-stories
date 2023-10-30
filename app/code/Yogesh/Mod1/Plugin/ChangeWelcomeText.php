<?php

namespace Yogesh\Mod1\Plugin;

use Magento\Theme\Block\Html\Header;

class ChangeWelcomeText
{

    function afterGetWelcome(Header $subject, $result)
    {
        $result = "Changed Welcome Text";
        return $result;
    }
}
