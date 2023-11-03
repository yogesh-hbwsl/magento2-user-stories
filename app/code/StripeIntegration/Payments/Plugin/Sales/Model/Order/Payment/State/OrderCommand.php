<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order\Payment\State;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order\StatusResolver;
use Magento\Framework\App\ObjectManager;

class OrderCommand
{
    /**
     * @var StatusResolver
     */
    private $statusResolver;

    public function __construct(StatusResolver $statusResolver = null)
    {
        $this->statusResolver = $statusResolver
            ? : ObjectManager::getInstance()->get(StatusResolver::class);
    }

    public function aroundExecute($subject, \Closure $proceed, OrderPaymentInterface $payment, $amount, OrderInterface $order)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        if ($payment->getMethod() == "stripe_payments_bank_transfers")
        {
            if ($payment->getIsTransactionPending())
            {
                $state = 'pending_payment';
                $status = $this->statusResolver->getOrderStatusByState($order, $state);
                $message = __("The order is pending a bank transfer of %1 from the customer.");

                $order->setState($state);
                $order->setStatus($status);
                return __($message, $order->getBaseCurrency()->formatTxt($amount));
            }
        }

        return $proceed($payment, $amount, $order);
    }
}
