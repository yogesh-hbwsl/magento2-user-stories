<?php

namespace Yogesh\Mod1\Controller\Adminhtml\Access;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;

class Access implements ActionInterface
{
    protected $request;

    public function __construct(Http $request)
    {
        $this->request = $request;
    }

    public function execute()
    {

        if ($this->request->getParam('access')) {
            echo "Access Granted";
            exit;
        }
        echo "Access Denied";
        exit;
    }
}
