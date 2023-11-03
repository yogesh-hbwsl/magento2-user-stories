<?php
namespace StripeIntegration\Payments\Model;

use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use Magento\Quote\Model\Quote;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote\Address\Total;

class InitialFee extends AbstractTotal
{
    private $helper;
    private $storeManager;

    public function __construct(
        \StripeIntegration\Payments\Helper\InitialFee $helper,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    )
    {
        $this->helper = $helper;
        $this->setCode('initial_fee');
        $this->storeManager = $storeManager;
    }

    /**
     * @param Quote $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     * @return $this
     */
    public function collect(
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ) {
        parent::collect($quote, $shippingAssignment, $total);

        $items = $shippingAssignment->getItems();
        if (!count($items))
            return $this;


        $quoteItems = [];
        foreach ($items as $item)
        {
            if ($item->getQuoteItem())
            {
                $addressQty = $item->getQty();
                $item = $item->getQuoteItem();
            }

            $quoteItems[] = $item;
        }

        $rate = $quote->getBaseToQuoteRate();
        if (is_numeric($rate))
        {
            $amount = $this->helper->getTotalInitialFeeFor($quoteItems, $quote, $rate);
            $baseAmount = round(floatval($amount / $rate), 2);
        }
        else
        {
            $amount = $this->helper->getTotalInitialFeeFor($quoteItems, $quote, 1);
            $baseAmount = $amount;
        }

        $total->setInitialFeeAmount($amount);
        $total->setBaseInitialFeeAmount($baseAmount);

        // Add the fee to the grand total
        $total->addTotalAmount('initial_fee', $amount);
        $total->addBaseTotalAmount('base_initial_fee', $baseAmount);

        return $this;
    }

    /**
     * @param Total $total
     */
    protected function clearValues(Total $total)
    {
        $total->setTotalAmount('initial_fee', 0);
        $total->setBaseTotalAmount('base_initial_fee', 0);
        $total->setInitialFeeAmount(0);
        $total->setBaseInitialFeeAmount(0);
        $total->setGrandTotal(0);
        $total->setBaseGrandTotal(0);

        // $total->setTotalAmount('tax', 0);
        // $total->setBaseTotalAmount('base_tax', 0);
        // $total->setTotalAmount('discount_tax_compensation', 0);
        // $total->setBaseTotalAmount('base_discount_tax_compensation', 0);
        // $total->setTotalAmount('shipping_discount_tax_compensation', 0);
        // $total->setBaseTotalAmount('base_shipping_discount_tax_compensation', 0);
        // $total->setSubtotalInclTax(0);
        // $total->setBaseSubtotalInclTax(0);
    }

    /**
     * @param Quote $quote
     * @param Total $total
     * @return array
     */
    public function fetch(Quote $quote, Total $total)
    {
        if ($quote->getIsMultiShipping())
            $baseAmount = $total->getInitialFeeAmount();
        else
            $baseAmount = $this->helper->getTotalInitialFeeFor($quote->getAllItems(), $quote, 1);

        $store = $this->storeManager->getStore();
        $amount = $store->getBaseCurrency()->convert($baseAmount, $store->getCurrentCurrencyCode());

        if ($baseAmount)
        {
            return [
                'code' => $this->getCode(),
                'title' => 'Initial Fee',
                'base_value' => $baseAmount,
                'value' => $amount
            ];
        }

        return null;
    }

    /**
     * @return \Magento\Framework\Phrase
     */
    public function getLabel()
    {
        return __('Initial Fee');
    }
}
