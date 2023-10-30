<?php

namespace Yogesh\Mod1\Plugin;

use Magento\Theme\Block\Html\Footer;

class ChangeCopyrightText
{

    function afterGetCopyright(Footer $subject, $result)
    {
        $result = "Changed Copyright Text";
        return $result;
    }
}
