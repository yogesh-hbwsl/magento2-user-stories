<?php

namespace Yogesh\Mod1\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Psr\Log\LoggerInterface;

class LogProductName implements ObserverInterface
{
    protected $logger;
    protected $response;


    public function __construct(
        LoggerInterface $logger

    ) {
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        $product = $observer->getEvent()->getProduct();
        if ($product) {
            $productName = $product->getName();
            $this->logger->info("Viewed Product Name: $productName");
        }
    }
}
