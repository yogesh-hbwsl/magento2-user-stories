<?php

namespace Yogesh\Mod1\Controller\HelloWorld;

use Magento\Framework\App\ActionInterface;

class HelloWorld implements ActionInterface
{
    public function execute()
    {
        echo "Hello World";
        exit;
    }
}
