<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\TrialSimple;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\SessionFactory as CheckoutSessionFactory;
use PHPUnit\Framework\Constraint\StringContains;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CheckoutTotalsExpiringCouponTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $service;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->service = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Discounts.php
     */
    public function testTrialCartCheckoutTotals()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("TrialSimple")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setCouponCode("10_percent_apply_once")
            ->setPaymentMethod("SuccessCard");

        $quote = $this->quote->getQuote();
        $this->assertEquals("10_percent_apply_once", $quote->getCouponCode());

        $trialSubscriptionsConfig = $this->service->get_trialing_subscriptions(
            $quote->getBillingAddress()->getData(),
            $quote->getShippingAddress()->getData(),
            $quote->getShippingAddress()->getShippingMethod(),
            $quote->getCouponCode()
        );
        $trialSubscriptionsConfig = json_decode($trialSubscriptionsConfig, true);

        $order = $this->quote->placeOrder();
        $this->assertEquals($order->getSubtotal(), $trialSubscriptionsConfig["subscriptions_total"], "Subtotal");
        $this->assertEquals($order->getBaseSubtotal(), $trialSubscriptionsConfig["base_subscriptions_total"], "Base Subtotal");

        $this->assertEquals($order->getShippingAmount(), $trialSubscriptionsConfig["shipping_total"], "Shipping");
        $this->assertEquals($order->getBaseShippingAmount(), $trialSubscriptionsConfig["base_shipping_total"], "Base Shipping");

        $this->assertEquals($order->getDiscountAmount(), -$trialSubscriptionsConfig["discount_total"], "Discount");
        $this->assertEquals($order->getBaseDiscountAmount(), -$trialSubscriptionsConfig["base_discount_total"], "Base Discount");

        $this->assertEquals($order->getTaxAmount(), $trialSubscriptionsConfig["tax_total"], "Tax");
        $this->assertEquals($order->getBaseTaxAmount(), $trialSubscriptionsConfig["tax_total"], "Base Tax");
    }
}
