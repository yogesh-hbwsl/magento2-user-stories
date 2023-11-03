<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order\Payment\State;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order\StatusResolver;
use Magento\Framework\App\ObjectManager;

class AuthorizeCaptureCommand
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
        if ($payment->getMethod() == "stripe_payments")
        {
            if ($payment->getIsTransactionPending())
            {
                $state = 'pending_payment';
                $status = $this->statusResolver->getOrderStatusByState($order, $state);
                if ($payment->getAdditionalInformation("is_future_subscription_setup"))
                {
                    $message = __("A subscription with a future start date has been created.");
                }
                else if ($payment->getAdditionalInformation("is_migrated_subscription"))
                {
                    $message = __("Order created via subscriptions CLI migration tool.");
                }
                else if ($payment->getAdditionalInformation("is_subscription_update"))
                {
                    $originalOrderIncrementId = $payment->getAdditionalInformation("original_order_increment_id");
                    $message = __("This order was created as part of a requested subscription change by the customer. No payment has been collected. Original order number #%1", $originalOrderIncrementId);
                }
                else
                {
                    $message = __("The customer's bank requested customer authentication. Beginning the authentication process.");
                }

                $order->setState($state);
                $order->setStatus($status);
                return __($message, $order->getBaseCurrency()->formatTxt($amount));
            }

            /** @var \Magento\Sales\Model\Order\Payment $payment */
            if ($payment->getAdditionalInformation("is_trial_subscription_setup"))
            {
                $state = 'processing';
                $status = $this->statusResolver->getOrderStatusByState($order, $state);
                $message = __("A trialing subscription has been set up.");
                $order->setState($state);
                $order->setStatus($status);
                return __($message, $order->getBaseCurrency()->formatTxt($amount));
            }
        }

        return $proceed($payment, $amount, $order);
    }
}
