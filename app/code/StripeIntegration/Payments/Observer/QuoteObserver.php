<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use StripeIntegration\Payments\Helper\Logger;

class QuoteObserver extends AbstractDataAssignObserver
{
    public $hasSubscriptions = null;

    private $config;
    private $helper;
    private $taxCalculation;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Tax\Calculation $taxCalculation
    )
    {
        $this->helper = $helper;
        $this->config = $config;
        $this->taxCalculation = $taxCalculation;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $quote = $observer->getEvent()->getQuote();
        $eventName = $observer->getEvent()->getName();

        if (empty($quote) || !$this->config->isEnabled())
            return;

        if ($this->config->priceIncludesTax())
            return;

        $this->taxCalculation->method = null;

        if ($this->hasSubscriptions === null)
            $this->hasSubscriptions = $this->helper->hasSubscriptionsIn($quote->getAllItems());

        if ($this->hasSubscriptions)
        {
            $this->taxCalculation->method = \Magento\Tax\Model\Calculation::CALC_ROW_BASE;
            return;
        }

        if ($quote->getPayment() && $quote->getPayment()->getMethod() == "stripe_payments_invoice")
        {
            $this->taxCalculation->method = \Magento\Tax\Model\Calculation::CALC_ROW_BASE;
            return;
        }
    }
}
