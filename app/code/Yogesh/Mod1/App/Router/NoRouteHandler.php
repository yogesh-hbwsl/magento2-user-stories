<?php

namespace Yogesh\Mod1\App\Router;

class NoRouteHandler implements \Magento\Framework\App\Router\NoRouteHandlerInterface
{
    public function process(\Magento\Framework\App\RequestInterface $request)
    {
        $request->setModuleName('contact')->setControllerName('index')->setActionName('index');
        return true;
    }
}
