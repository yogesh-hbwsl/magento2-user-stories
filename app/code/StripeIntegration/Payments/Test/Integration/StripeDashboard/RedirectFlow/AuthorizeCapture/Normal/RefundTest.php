<?php

namespace StripeIntegration\Payments\Test\Integration\StripeDashboard\RedirectFlow\AuthorizeCapture\Normal;

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
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     */
    public function testPartialRefund()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Berlin")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Berlin")
            ->setPaymentMethod("StripeCheckout");

        // Place the order
        $order = $this->quote->placeOrder();
        $orderIncrementId = $order->getIncrementId();
        $currency = $order->getOrderCurrencyCode();
        $amount = $this->tests->helper()->convertMagentoAmountToStripeAmount($order->getGrandTotal(), $currency);

        // Confirm the payment
        $method = "card";
        $session = $this->tests->checkout()->retrieveSession($order);
        $response = $this->tests->checkout()->confirm($session, $order, $method, "Berlin");
        $this->tests->checkout()->authenticate($response->payment_intent, $method);

        // Trigger webhooks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);
        $this->tests->event()->triggerPaymentIntentEvents($paymentIntent);

        $stripe = $this->tests->stripe();

        // Partially refund the charge
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals($response->payment_intent->id, $order->getPayment()->getLastTransId());
        $refund = $stripe->refunds->create(['charge' => $paymentIntent->charges->data[0], 'amount' => 500]);

        // charge.refunded
        $this->tests->event()->trigger("charge.refunded", $paymentIntent->charges->data[0]->id);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(5, $order->getTotalRefunded());

        // Refund the remaining amount
        $remainingAmount = ($order->getGrandTotal() - $order->getTotalRefunded()) * 100;
        $refund = $stripe->refunds->create(['charge' => $paymentIntent->charges->data[0], 'amount' => $remainingAmount]);

        // charge.refunded
        $this->tests->event()->trigger("charge.refunded", $paymentIntent->charges->data[0]->id);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());
        $this->assertEquals("closed", $order->getStatus());
    }
}
