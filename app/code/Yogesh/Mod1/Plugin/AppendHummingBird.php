<?php


namespace Yogesh\Mod1\Plugin;

use Magento\Theme\Block\Html\Breadcrumbs;

class AppendHummingBird
{
    public function beforeAddCrumb(Breadcrumbs $subject, $crumbName, $crumbInfo)
    {

        $crumbInfo['label'] = 'Hummingbird ' . $crumbInfo['label'];
        return [$crumbName, $crumbInfo];
    }
}
