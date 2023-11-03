<?php
namespace StripeIntegration\Payments\Model\Order;

use StripeIntegration\Payments\Model\InitialFee;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderExtensionInterface;
use Magento\Sales\Model\Order;

class InitialFeeManagement
{
    public $initialFee = 0;
    public $baseInitialFee = 0;

    public function setFromData(Order $order)
    {
        $this->initialFee = $order->getData('initial_fee');
        $this->baseInitialFee = $order->getData('base_initial_fee');

        return $order;
    }

    public function setFromAddressData(Order $order, QuoteAddress $address)
    {
        $this->initialFee = $address->getData('initial_fee');
        $this->baseInitialFee = $address->getData('base_initial_fee');

        return $order;
    }

    public function setDataFrom(Order $order)
    {
        $order->setData('initial_fee', $this->initialFee);
        $order->setData('base_initial_fee', $this->baseInitialFee);

        return $order;
    }
}
