<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use StripeIntegration\Payments\Helper\Logger;

class PaymentMethodActiveObserver extends AbstractDataAssignObserver
{
    private $helper;
    private $config;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $this->helper = $helper;
        $this->config = $config;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $quote = $observer->getEvent()->getQuote();

        if (!$this->config->isSubscriptionsEnabled())
            return;

        $result = $observer->getEvent()->getResult();
        $methodInstance = $observer->getEvent()->getMethodInstance();
        $code = $methodInstance->getCode();
        $isAvailable = $result->getData('is_available');

        // No need to check if its already false
        if (!$isAvailable)
            return;

        // Can't check without a quote
        if (!$quote)
            return;

        if ($this->helper->supportsSubscriptions($code) && !$this->helper->isMultiShipping($quote))
            return;

        // Disable all other payment methods if we have subscriptions
        if ($this->helper->hasSubscriptions())
            $result->setData('is_available', false);
    }
}
