<?php
namespace StripeIntegration\Payments\Model\Invoice\Total;

use Magento\Quote\Model\Quote;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote\Address\Total;
use StripeIntegration\Payments\Helper\Logger;

class InitialFee extends \Magento\Sales\Model\Order\Total\AbstractTotal
{
    private $helper;

    public function __construct(
        \StripeIntegration\Payments\Helper\InitialFee $helper
    )
    {
        $this->helper = $helper;
    }

    /**
     * @return $this
     */
    public function collect(
        \Magento\Sales\Model\Order\Invoice $invoice
    ) {

        $baseAmount = $this->helper->getTotalInitialFeeForInvoice($invoice, false);

        if (is_numeric($invoice->getBaseToOrderRate()))
            $amount = round(floatval($baseAmount * $invoice->getBaseToOrderRate()), 4);
        else
            $amount = $baseAmount;

        $invoice->setGrandTotal($invoice->getGrandTotal() + $amount);
        $invoice->setBaseGrandTotal($invoice->getBaseGrandTotal() + $baseAmount);

        return $this;
    }
}
