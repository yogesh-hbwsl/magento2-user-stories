<?php

namespace Yogesh\Mod1\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

use Psr\Log\LoggerInterface;

use function Psy\info;

class LogHtml implements ObserverInterface
{
    private $_logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->_logger = $logger;
    }

    public function execute(Observer $observer)
    {
        $html = $observer->getEvent()->getData('response')->getBody();
        $this->_logger->info("Html is => " . $html);
    }
}
