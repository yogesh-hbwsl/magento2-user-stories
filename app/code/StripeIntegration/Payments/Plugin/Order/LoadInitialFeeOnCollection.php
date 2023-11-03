<?php
namespace StripeIntegration\Payments\Plugin\Order;

use StripeIntegration\Payments\Model\Order\InitialFeeManagement;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;

class LoadInitialFeeOnCollection
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

    public function afterGetItems(OrderCollection $subject, array $orders)
    {
        return array_map(function (Order $order) {
            return $this->initialFeeManagement->setFromData($order);
        }, $orders);
    }
}
