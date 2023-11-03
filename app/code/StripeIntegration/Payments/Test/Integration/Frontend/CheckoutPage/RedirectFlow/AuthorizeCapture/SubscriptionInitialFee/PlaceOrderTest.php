<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\SubscriptionInitialFee;

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
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testGuestSubscription()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("SubscriptionInitialFee")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();
        $this->tests->assertCheckoutSessionsCountEquals(1);

        // Place the order
        $order = $this->quote->placeOrder();

        // Confirm the payment
        $method = "SuccessCard";
        $session = $this->tests->checkout()->retrieveSession($order);
        $response = $this->tests->checkout()->confirm($session, $order, $method, "Berlin");
        $this->tests->checkout()->authenticate($response->payment_intent, $method);
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);

        // Trigger webhooks
        $this->tests->event()->triggerPaymentIntentEvents($paymentIntent);

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Check if Radar risk value is been set to the order
        $this->assertIsNumeric($order->getStripeRadarRiskScore());
        $this->assertGreaterThanOrEqual(0, $order->getStripeRadarRiskScore());
        $this->assertNotEquals('NA', $order->getStripeRadarRiskLevel());

        // Check Stripe Payment method
        $paymentMethod = $this->tests->loadPaymentMethod($order->getId());
        $this->assertEquals('card', $paymentMethod->getPaymentMethodType());

        // Ensure that we re-used the cached session from the api
        $this->tests->assertCheckoutSessionsCountEquals(1);

        $lastCheckoutSession = $this->tests->getLastCheckoutSession();
        $customer = $this->tests->getStripeCustomer();
        $this->assertNotEmpty($customer);

        $this->tests->compare($lastCheckoutSession, [
            "amount_total" => $order->getGrandTotal() * 100,
            "payment_intent" => null,
            "customer_email" => "unset",
            "customer" => $customer->id,
            "submit_type" => null,
            "mode" => "subscription",
            "subscription" => [
                "plan" => [
                    "amount" => $order->getGrandTotal() * 100 - 255 // We subtract the initial fee which is billed once only
                ]
            ]
        ]);

        $savedMethods = $this->tests->stripe()->customers->allPaymentMethods($customer->id, ['type' => 'card']);
        $this->assertCount(1, $savedMethods->data);
        $this->assertStringContainsString("pm_", $savedMethods->data[0]->id);
    }
}
