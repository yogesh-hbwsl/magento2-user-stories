<?php
namespace StripeIntegration\Payments\Plugin\Order;

use StripeIntegration\Payments\Model\Order\InitialFeeManagement;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class SaveInitialFee
{
    /**
     * @var InitialFeeManagement
     */
    private $initialFeeManagement;

    public function __construct(InitialFeeManagement $initialFeeManagement)
    {
        $this->initialFeeManagement = $initialFeeManagement;
    }

    public function beforeSave(OrderRepositoryInterface $subject, Order $order)
    {
        return [$this->initialFeeManagement->setDataFrom($order)];
    }
}
