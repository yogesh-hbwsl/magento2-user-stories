<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeOnly\ManualInvoicing\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ExpiredAuthorizationsTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/expired_authorizations 1
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testOffSessionSetupFutureUsage()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("StripeCheckout");

        $this->tests->assertCheckoutSessionsCountEquals(1);

        // Place the order
        $order = $this->quote->placeOrder();

        // Ensure that we re-used the cached session from the api
        $this->tests->assertCheckoutSessionsCountEquals(1);

        $lastCheckoutSession = $this->tests->getLastCheckoutSession();
        $customer = $this->tests->getStripeCustomer();
        $this->assertNotEmpty($customer);

        $this->tests->compare($lastCheckoutSession, [
            "amount_total" => $order->getGrandTotal() * 100,
            "payment_intent" => [
                "amount" => $order->getGrandTotal() * 100,
                "capture_method" => "manual",
                "description" => "Order #" . $order->getIncrementId() . " by Mario Osterhagen",
                "setup_future_usage" => "on_session",
                "customer" => $customer->id
            ],
            "customer_email" => "unset",
            "customer" => $customer->id,
            "submit_type" => "pay"
        ]);
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/expired_authorizations 0
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testOnlyWarnNoSave()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();

        $this->tests->assertCheckoutSessionsCountEquals(1);

        // Place the order
        $order = $this->quote->placeOrder();

        // Ensure that we re-used the cached session from the api
        $this->tests->assertCheckoutSessionsCountEquals(1);

        $lastCheckoutSession = $this->tests->getLastCheckoutSession();
        $customer = $this->tests->getStripeCustomer();
        $this->assertEmpty($customer);

        $this->tests->compare($lastCheckoutSession, [
            "amount_total" => $order->getGrandTotal() * 100,
            "payment_intent" => [
                "amount" => $order->getGrandTotal() * 100,
                "capture_method" => "manual",
                "description" => "Order #" . $order->getIncrementId() . " by Mario Osterhagen",
                "setup_future_usage" => "unset",
                "customer" => "unset"
            ],
            "customer_email" => "osterhagen@example.com",
            "customer" => "unset",
            "submit_type" => "pay"
        ]);
    }
}
