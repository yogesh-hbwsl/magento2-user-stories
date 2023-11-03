<?php

namespace StripeIntegration\Payments\Plugin\QuoteGraphQl\Model\Cart;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

class SetPaymentMethodOnCart
{
    private $config;
    private $helper;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper
    ) {
        $this->config = $config;
        $this->helper = $helper;
    }

    public function afterExecute(
        \Magento\QuoteGraphQl\Model\Cart\SetPaymentMethodOnCart $subject,
        $result,
        \Magento\Quote\Model\Quote $cart,
        array $paymentData
    ): void {
        if (!empty($paymentData["stripe_payments"])) {
            $payment = $cart->getPayment();
            $this->helper->assignPaymentData($payment, $paymentData["stripe_payments"]);
            $payment->save();
        }
    }
}
