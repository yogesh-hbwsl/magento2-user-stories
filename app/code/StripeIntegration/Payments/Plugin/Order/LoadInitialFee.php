<?php
namespace StripeIntegration\Payments\Plugin\Order;

use StripeIntegration\Payments\Model\Order\InitialFeeManagement;
use Magento\Sales\Model\Order;

class LoadInitialFee
{
    /**
     * @var InitialFeeManagement
     */
    private $extensionManagement;
    private $initialFeeManagement;

    public function __construct(InitialFeeManagement $initialFeeManagement)
    {
        $this->initialFeeManagement = $initialFeeManagement;
    }

    public function afterLoad(Order $subject, Order $returnedOrder)
    {
        return $this->initialFeeManagement->setFromData($returnedOrder);
    }
}
