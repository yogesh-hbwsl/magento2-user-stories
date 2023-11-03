<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use StripeIntegration\Payments\Helper\Logger;

class AddInitialFeeTaxObserver implements ObserverInterface
{
    public $helper = null;
    public $taxHelper = null;
    public $subscriptionsHelper = null;

    private $config;
    private $paymentsHelperFactory;
    private $subscriptionsHelperFactory;
    private $taxHelperFactory;

    public function __construct(
        \StripeIntegration\Payments\Helper\GenericFactory $paymentsHelper,
        \StripeIntegration\Payments\Helper\TaxHelperFactory $taxHelperFactory,
        \StripeIntegration\Payments\Helper\SubscriptionsFactory $subscriptionsHelperFactory,
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $this->paymentsHelperFactory = $paymentsHelper;
        $this->taxHelperFactory = $taxHelperFactory;
        $this->subscriptionsHelperFactory = $subscriptionsHelperFactory;
        $this->config = $config;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return $this;

        $total = $observer->getData('total');
        $quote = $observer->getData('quote');

        if ($total && $total->getInitialFeeAmount() > 0)
            $this->applyInitialFeeTax($quote, $total);

        return $this;
    }

    public function applyInitialFeeTax($quote, $total)
    {
        if ($this->config->priceIncludesTax())
            return;

        $baseExtraTax = 0;
        $extraTax = 0;

        if (!$this->helper)
            $this->helper = $this->paymentsHelperFactory->create();

        if (!$this->subscriptionsHelper)
            $this->subscriptionsHelper = $this->subscriptionsHelperFactory->create();

        foreach ($quote->getAllItems() as $item)
        {
            $product = $this->helper->getSubscriptionProductFromQuoteItem($item);
            if (!$product)
                continue;

            if (!$quote->getQuoteCurrencyCode())
            {
                $quote->beforeSave(); // Sets the currencies
            }

            $profile = $this->subscriptionsHelper->getSubscriptionDetails($product, $quote, $item);

            $baseExtraTax += $profile['base_tax_amount_initial_fee'];
            $extraTax += $profile['tax_amount_initial_fee'];
        }

        $total->addTotalAmount('tax', $extraTax);
        $total->addBaseTotalAmount('tax', $baseExtraTax);
        $total->setGrandTotal(round(floatval($total->getGrandTotal()) + floatval($extraTax), 4));
        $total->setBaseGrandTotal(round(floatval($total->getBaseGrandTotal()) + floatval($baseExtraTax), 2));
    }
}
