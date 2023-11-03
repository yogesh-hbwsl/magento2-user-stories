<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SavePaymentMethodTest extends \PHPUnit\Framework\TestCase
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
    public function testGuestNormalCapture()
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

        // Confirm the payment
        $method = "SuccessCard";
        $session = $this->tests->checkout()->retrieveSession($order);
        $response = $this->tests->checkout()->confirm($session, $order, $method, "Berlin");
        $this->tests->checkout()->authenticate($response->payment_intent, $method);
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);

        // Trigger webhooks
        $this->tests->event()->triggerPaymentIntentEvents($paymentIntent);

        // Ensure that we re-used the cached session from the api
        $this->tests->assertCheckoutSessionsCountEquals(1);

        $lastCheckoutSession = $this->tests->getLastCheckoutSession();
        $customer = $this->tests->getStripeCustomer();
        $this->assertNotEmpty($customer);

        $this->tests->compare($lastCheckoutSession, [
            "amount_total" => $order->getGrandTotal() * 100,
            "payment_intent" => [
                "amount" => $order->getGrandTotal() * 100,
                "capture_method" => "automatic",
                "description" => "Order #" . $order->getIncrementId() . " by Mario Osterhagen",
                "setup_future_usage" => "on_session",
                "customer" => $customer->id
            ],
            "customer_email" => "unset",
            "customer" => $customer->id,
            "submit_type" => "pay"
        ]);

        $savedMethods = $this->tests->stripe()->customers->allPaymentMethods($customer->id, ['type' => 'card']);
        $this->assertCount(1, $savedMethods->data);
        $this->assertStringContainsString("pm_", $savedMethods->data[0]->id);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testGuestNormalAuthorize()
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

        // Confirm the payment
        $method = "SuccessCard";
        $session = $this->tests->checkout()->retrieveSession($order);
        $response = $this->tests->checkout()->confirm($session, $order, $method, "Berlin");
        $this->tests->checkout()->authenticate($response->payment_intent, $method);
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);

        // Trigger webhooks
        $this->tests->event()->triggerPaymentIntentEvents($paymentIntent);

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

        $savedMethods = $this->tests->stripe()->customers->allPaymentMethods($customer->id, ['type' => 'card']);
        $this->assertCount(1, $savedMethods->data);
        $this->assertStringContainsString("pm_", $savedMethods->data[0]->id);
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Customer.php
     */
    public function testLoggedInNormalCapture()
    {
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("Normal")
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

        // Ensure that we re-used the cached session from the api
        $this->tests->assertCheckoutSessionsCountEquals(1);

        $lastCheckoutSession = $this->tests->getLastCheckoutSession();
        $customer = $this->tests->getStripeCustomer();
        $this->assertNotEmpty($customer);

        $this->tests->compare($lastCheckoutSession, [
            "amount_total" => $order->getGrandTotal() * 100,
            "payment_intent" => [
                "amount" => $order->getGrandTotal() * 100,
                "capture_method" => "automatic",
                "description" => "Order #" . $order->getIncrementId() . " by Mr. John Smith Esq.",
                "setup_future_usage" => "on_session",
                "customer" => $customer->id
            ],
            "customer_email" => "unset",
            "customer" => $customer->id,
            "submit_type" => "pay"
        ]);

        $savedMethods = $this->tests->stripe()->customers->allPaymentMethods($customer->id, ['type' => 'card']);
        $this->assertCount(1, $savedMethods->data);
        $this->assertStringContainsString("pm_", $savedMethods->data[0]->id);

        // In the customer account section, this is how we retrieve saved cards
        $paymentMethods = $this->tests->helper()->getCustomerModel()->getSavedPaymentMethods();
        $customerCards = $paymentMethods['card'];
        $this->assertCount(1, $customerCards);
        $this->assertEquals($savedMethods->data[0]->id, $customerCards[0]->id);
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Customer.php
     */
    public function testLoggedInNormalAuthorize()
    {
        $this->quote->create()
            ->setCustomer('LoggedIn')
            ->setCart("Normal")
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

        // Trigger webhooks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);
        $this->tests->event()->triggerPaymentIntentEvents($paymentIntent);

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
                "description" => "Order #" . $order->getIncrementId() . " by Mr. John Smith Esq.",
                "setup_future_usage" => "on_session",
                "customer" => $customer->id
            ],
            "customer_email" => "unset",
            "customer" => $customer->id,
            "submit_type" => "pay"
        ]);

        $savedMethods = $this->tests->stripe()->customers->allPaymentMethods($customer->id, ['type' => 'card']);
        $this->assertCount(1, $savedMethods->data);
        $this->assertStringContainsString("pm_", $savedMethods->data[0]->id);

        // In the customer account section, this is how we retrieve saved cards
        $paymentMethods = $this->tests->helper()->getCustomerModel()->getSavedPaymentMethods();
        $customerCards = $paymentMethods['card'];
        $this->assertCount(1, $customerCards);
        $this->assertEquals($savedMethods->data[0]->id, $customerCards[0]->id);
    }
}
