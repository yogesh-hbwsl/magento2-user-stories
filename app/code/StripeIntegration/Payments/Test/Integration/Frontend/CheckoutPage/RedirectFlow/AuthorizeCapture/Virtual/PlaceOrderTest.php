<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Virtual;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Virtual")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();
        $this->assertContains("card", $methods);
        $this->assertContains("bancontact", $methods);
        $this->assertContains("eps", $methods);
        $this->assertContains("giropay", $methods);
        $this->assertContains("p24", $methods);
        $this->assertContains("sepa_debit", $methods);
        $this->assertContains("sofort", $methods);

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
                "capture_method" => "automatic",
                "description" => "Order #" . $order->getIncrementId() . " by Mario Osterhagen",
                "setup_future_usage" => "unset",
                "customer" => "unset"
            ],
            "customer_email" => "osterhagen@example.com",
            "submit_type" => "pay"
        ]);
    }
}
