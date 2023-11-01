<?php

namespace Yogesh\Mod1\Controller\Adminhtml\ShowText;

use Magento\Framework\App\ActionInterface;

class Index implements ActionInterface
{
    public function execute()
    {
        echo "show";
        exit;
    }
}
