<?php

namespace StripeIntegration\Payments\Test\Integration\StripeDashboard\RedirectFlow\AuthorizeOnly\ManualInvoicing\MixedCart;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RefundTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     */
    public function testPartialCapture()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("MixedCart")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("StripeCheckout");

        // Place the order
        $order = $this->quote->placeOrder();

        // Confirm the payment
        $method = "card";
        $session = $this->tests->checkout()->retrieveSession($order);
        $response = $this->tests->checkout()->confirm($session, $order, $method, "Berlin");
        $this->tests->checkout()->authenticate($response->payment_intent, $method);

        // Stripe checks
        $customerId = $session->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);

        // Trigger webhooks
        // charge.succeeded & payment_intent.succeeded & invoice.payment_succeeded
        $subscription = $customer->subscriptions->data[0];
        $this->tests->event()->triggerSubscriptionEvents($subscription);

        // Partially refund the charge
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);
        $refund = $this->tests->stripe()->refunds->create(['charge' => $paymentIntent->charges->data[0], 'amount' => 500]);

        // charge.refunded
        $this->tests->event()->trigger("charge.refunded", $paymentIntent->charges->data[0]->id);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(5, $order->getTotalRefunded());

        // Refund the remaining amount
        $remainingAmount = ($order->getGrandTotal() - $order->getTotalRefunded()) * 100;
        $refund = $this->tests->stripe()->refunds->create(['charge' => $paymentIntent->charges->data[0], 'amount' => $remainingAmount]);

        // charge.refunded
        $this->tests->event()->trigger("charge.refunded", $paymentIntent->charges->data[0]->id);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());
        $this->assertEquals("closed", $order->getStatus());
    }
}
