<?php

namespace Yogesh\Mod1\Observer;

use Magento\Framework\App\RouterList;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

use Psr\Log\LoggerInterface;

class LogRouter implements ObserverInterface
{
    private $_logger;
    private $_routerList;

    public function __construct(LoggerInterface $logger, RouterList $routerList)
    {
        $this->_logger = $logger;
        $this->_routerList = $routerList;
    }

    public function execute(Observer $observer)
    {
        $routerList = $this->_routerList;
        while ($routerList->valid()) {
            $this->_logger->info($routerList->key());
            $routerList->next();
        }
        // print $routerList->valid();
        // echo $routerList->key();
        // $this->_logger->info("Router List => " . $this->_routerList);
    }
}
