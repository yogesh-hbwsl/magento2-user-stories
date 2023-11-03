<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Helper\Logger;

class TaxHelper
{
    private $taxHelper;
    private $taxOrderFactory;
    private $taxCalculation;
    private $taxItem;

    public function __construct(
        \Magento\Sales\Model\ResourceModel\Order\Tax\Item $taxItem,
        \Magento\Tax\Helper\Data $taxHelper,
        \Magento\Tax\Model\Sales\Order\TaxFactory $taxOrderFactory,
        \Magento\Tax\Model\Calculation $taxCalculation
    ) {
        $this->taxItem = $taxItem;
        $this->taxHelper = $taxHelper;
        $this->taxOrderFactory = $taxOrderFactory;
        $this->taxCalculation = $taxCalculation;
    }

    public function getShippingTaxRatesFromQuote($quote)
    {
        if ($quote->getIsVirtual())
            return [];

        $shippingAddress = $quote->getShippingAddress();
        if (empty($shippingAddress))
            return [];

        $quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates();

        return $quote->getShippingAddress()->getAppliedTaxes();
    }

    public function getShippingTaxRateFromQuote($quote)
    {
        if ($quote->getIsVirtual())
            return null;

        $shippingAddress = $quote->getShippingAddress();
        if (empty($shippingAddress))
            return null;

        $quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates();

        $itemsAppliedTaxes = $quote->getShippingAddress()->getItemsAppliedTaxes();

        if (empty($itemsAppliedTaxes))
            return null;

        foreach ($itemsAppliedTaxes as $appliedTax)
        {
            foreach ($appliedTax as $taxRate)
            {
                if ($taxRate["item_type"] == "shipping")
                {
                    return $taxRate;
                }
            }
        }

        return null;
    }

    public function taxInclusiveTaxCalculator($fullAmount, $taxPercent)
    {
        if ($taxPercent <= 0 || $fullAmount <= 0 || !is_numeric($fullAmount))
            return 0;

        $taxDivider = (1 + $taxPercent / 100); // i.e. Convert 8.25 to 1.0825
        $amountWithoutTax = round(floatval($fullAmount / $taxDivider), 2); // Magento seems to sometimes be flooring instead of rounding tax inclusive prices
        return  $fullAmount - $amountWithoutTax;
    }

    public function taxExclusiveTaxCalculator($fullAmount, $taxPercent)
    {
        if ($taxPercent <= 0 || $fullAmount <= 0 || !is_numeric($fullAmount))
            return 0;

        return round(floatval($fullAmount * ($taxPercent / 100)), 2);
    }

    public function getShippingTaxRateFromOrder($order, $product)
    {
        if ($order->getIsVirtual())
            return null;

        $shippingAddress = $order->getShippingAddress();
        if (empty($shippingAddress))
            return null;

        $itemsAppliedTaxes = $this->taxHelper->getCalculatedTaxes($order);
        if (empty($itemsAppliedTaxes))
        {
            $rates = $this->taxOrderFactory->create()->getCollection()->loadByOrder($order)->toArray();
            $itemsAppliedTaxes = $this->taxCalculation->reproduceProcess($rates['items']);
        }

        // Tax Calculation
        $productTaxClassId = $product->getTaxClassId();
        // $defaultCustomerTaxClassId = $this->scopeConfig->getValue('tax/classes/default_customer_tax_class');

        $request = new \Magento\Framework\DataObject(
            [
                'country_id' => $shippingAddress->getCountryId(),
                'region_id' => $shippingAddress->getRegionId(),
                'postcode' => $shippingAddress->getPostcode(),
                'customer_class_id' => 3,
                'product_class_id' => $productTaxClassId
            ]
        );

        // Calculate tax
        $taxInfo = $this->taxCalculation->getResource()->getRateInfo($request);

        return $taxInfo;

        // if (empty($itemsAppliedTaxes))
        //     return null;

        // foreach ($itemsAppliedTaxes as $appliedTax)
        // {
        //     foreach ($appliedTax as $taxRate)
        //     {
        //         if ($taxRate["item_type"] == "shipping")
        //         {
        //             return $taxRate;
        //         }
        //     }
        // }

        // return null;
    }

    public function getShippingTaxPercentFromRate($rate)
    {
        if (empty($rate['percent']))
            return 0;

        if (is_numeric($rate['percent']) && $rate['percent'] > 0 && $rate['item_type'] == "shipping")
            return $rate['percent'] / 100;

        return 0;
    }

    public function getProductTaxPercentFromRate($rate)
    {
        if (empty($rate['percent']))
            return 0;

        if (is_numeric($rate['percent']) && $rate['percent'] > 0 && $rate['item_type'] == "product")
            return $rate['percent'] / 100;

        return 0;
    }

    public function getBaseShippingAmountForQuoteItem($quoteItem, $quote)
    {
        if ($quote->getIsVirtual())
            return 0;

        if ($quoteItem->getProductType() == "virtual" || $quoteItem->getProductType() == "downloadable")
            return 0;

        $shippingAddress = $quote->getShippingAddress();

        if ($quoteItem->getQtyCalculated())
        {
            $originalQty = $quoteItem->getQty();
            $quoteItem->setQty($quoteItem->getQtyCalculated());
            $shippingAddress->requestShippingRates($quoteItem);
            $quoteItem->setQty($originalQty);
        }
        else
            $shippingAddress->requestShippingRates($quoteItem);

        return floatval($quoteItem->getBaseShippingAmount());
    }

    public function getBaseShippingTaxFor($quoteItem, $quote)
    {
        if ($quote->getIsVirtual())
            return 0;

        $baseShippingAmount = $this->getBaseShippingAmountForQuoteItem($quoteItem, $quote);
        if ($baseShippingAmount == 0)
            return 0;

        $tax = 0;
        $rates = $this->getShippingTaxRatesFromQuote($quote);
        foreach ($rates as $rate)
        {
            $percent = $this->getShippingTaxPercentFromRate($rate);
            $tax += round(floatval($baseShippingAmount * $percent), 2);
        }

        return $tax;
    }

    public function getTaxPercentForOrder($orderId, $type = "product")
    {
        $taxItems = $this->taxItem->getTaxItemsByOrderId($orderId);

        if (is_array($taxItems))
        {
            foreach ($taxItems as $taxItem)
            {
                if ($taxItem['taxable_item_type'] == $type && $taxItem['tax_percent'])
                {
                    return $taxItem['tax_percent'];
                }
            }
        }

        return 0;
    }
}
