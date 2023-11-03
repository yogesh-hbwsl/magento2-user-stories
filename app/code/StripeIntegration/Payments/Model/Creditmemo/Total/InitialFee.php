<?php
namespace StripeIntegration\Payments\Model\Creditmemo\Total;

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
        \Magento\Sales\Model\Order\Creditmemo $creditmemo
    ) {
        $baseAmount = $this->helper->getTotalInitialFeeForCreditmemo($creditmemo, false);
        if (is_numeric($creditmemo->getBaseToOrderRate()))
            $amount = round(floatval($baseAmount * $creditmemo->getBaseToOrderRate()), 4);
        else if (is_numeric($creditmemo->getBaseToQuoteRate()))
            $amount = round(floatval($baseAmount * $creditmemo->getBaseToQuoteRate()), 4);
        else
            $amount = $baseAmount;

        $creditmemo->setGrandTotal($creditmemo->getGrandTotal() + $amount);
        $creditmemo->setBaseGrandTotal($creditmemo->getBaseGrandTotal() + $baseAmount);

        return $this;
    }
}
