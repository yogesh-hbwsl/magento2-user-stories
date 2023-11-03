<?php

namespace StripeIntegration\Payments\Test\Integration\StripeDashboard\RedirectFlow\AuthorizeOnly\ManualInvoicing\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CaptureTest extends \PHPUnit\Framework\TestCase
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

        // Order checks
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(0, $order->getTotalPaid());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalDue());
        $this->assertTrue($order->canInvoice());

        // Partially capture the charge
        $paymentIntentId = $order->getPayment()->getLastTransId();
        $paymentIntent = $this->tests->stripe()->paymentIntents->capture($paymentIntentId, ["amount_to_capture" => 500]);
        $this->assertEquals(500, $paymentIntent->amount_received);
        $this->tests->event()->trigger("charge.captured", $paymentIntent->charges->data[0]);
        $this->tests->event()->trigger("payment_intent.succeeded", $paymentIntent);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $this->assertFalse($order->canInvoice());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(5, $order->getTotalPaid());
        $this->assertEquals($order->getGrandTotal() - 5, $order->getTotalDue());

        // Check that an invoice was created
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertNotEmpty($invoice);
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());
        $this->assertEquals(5, $invoice->getGrandTotal());
    }
}
