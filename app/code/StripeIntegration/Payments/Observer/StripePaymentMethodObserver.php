<?php

namespace StripeIntegration\Payments\Observer;

use StripeIntegration\Payments\Helper\PaymentMethod as HelperPaymentMethod;
use Magento\Framework\Event\ObserverInterface;

class StripePaymentMethodObserver implements ObserverInterface
{
    protected $helperPaymentMethod;

    public function __construct(
        HelperPaymentMethod $helperPaymentMethod
    )
    {
        $this->helperPaymentMethod = $helperPaymentMethod;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $extensionAttributes = $order->getExtensionAttributes();

        if (null !== $extensionAttributes && method_exists($extensionAttributes, 'getPaymentMethodType') &&
            null !== $extensionAttributes->getPaymentMethodType()
        ) {
            $paymentMethodType = $extensionAttributes->getPaymentMethodType();

            if ($paymentMethodType && method_exists($extensionAttributes, 'getPaymentMethodCardData')) {
                $cardData = $extensionAttributes->getPaymentMethodCardData();
                $this->helperPaymentMethod->savePaymentMethod($order->getId(), $paymentMethodType, $cardData);
            }
        }
    }
}
