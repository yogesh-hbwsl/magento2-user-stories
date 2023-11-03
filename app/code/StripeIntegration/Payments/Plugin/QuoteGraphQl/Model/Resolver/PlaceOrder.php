<?php

namespace StripeIntegration\Payments\Plugin\QuoteGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

class PlaceOrder
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

    public function afterResolve(
        \Magento\QuoteGraphQl\Model\Resolver\PlaceOrder $subject,
        $result,
        \Magento\Framework\GraphQl\Config\Element\Field $field,
        $context,
        \Magento\Framework\GraphQl\Schema\Type\ResolveInfo $info,
        array $value = null,
        array $args = null
    )
    {
        if (!empty($result["order"]["order_number"]))
        {
            $order = $this->helper->loadOrderByIncrementId($result["order"]["order_number"]);
            $payment = $order->getPayment();

            if ($payment->getMethod() == "stripe_payments" && $payment->getAdditionalInformation("client_secret"))
            {
                $result["order"]["client_secret"] = $payment->getAdditionalInformation("client_secret");
            }
        }

        return $result;
    }
}
