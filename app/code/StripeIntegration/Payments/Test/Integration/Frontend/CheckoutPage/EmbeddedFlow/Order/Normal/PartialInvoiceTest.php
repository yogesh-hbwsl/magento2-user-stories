<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\EmbeddedFlow\Order\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PartialInvoiceTest extends \PHPUnit\Framework\TestCase
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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action order
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     */
    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        // Refresh the order
        $order = $this->tests->refreshOrder($order);
        $payment = $order->getPayment();
        $this->assertNotEmpty($payment->getAdditionalInformation('customer_stripe_id'));
        $this->assertNotEmpty($payment->getAdditionalInformation('token'));
        $this->assertEquals('order', $payment->getAdditionalInformation('payment_action'));;

        // Order checks
        $this->assertTrue($order->canEdit());
        $this->assertCount(0, $order->getInvoiceCollection());
        $this->assertEquals(0, $order->getTotalPaid());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalDue());
        $this->assertEquals("processing", $order->getStatus());

        // Partially invoice the order
        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea('adminhtml');
        $this->tests->invoiceOnline($order, ['simple-product' => 2]);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $paymentIntentId = $order->getPayment()->getLastTransId();
        $this->assertNotEmpty($paymentIntentId);

        // Order checks
        $this->assertEquals(53.30, $order->getGrandTotal());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertEquals(21.65, $order->getTotalDue());
        $this->assertEquals(31.65, $order->getTotalInvoiced());
        $this->assertEquals(31.65, $order->getTotalPaid());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        // Trigger webhooks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId);
        $this->tests->event()->trigger("charge.captured", $paymentIntent->charges->data[0]);
        $this->tests->event()->trigger("payment_intent.succeeded", $paymentIntent);

        // Stripe checks
        $this->assertEquals(3165, $paymentIntent->amount);
        $this->assertEquals(0, $paymentIntent->amount_capturable);

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertEquals(31.65, $invoice->getGrandTotal());
        $this->assertEquals(2, $invoice->getTotalQty());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Invoice the remaining amount. This should create a second payment in Stripe.
        $this->tests->invoiceOnline($order, ['virtual-product' => 2]);

        // Refresh the order object
        $order = $this->tests->helper()->loadOrderByIncrementId($order->getIncrementId());
        $newPaymentIntentId = $order->getPayment()->getLastTransId();
        $this->assertNotEmpty($newPaymentIntentId);
        $this->assertNotEquals($paymentIntentId, $newPaymentIntentId);

        // Order checks
        $this->assertEquals(53.30, $order->getGrandTotal());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalInvoiced());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(2, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getLastItem();
        $this->assertNotEmpty($invoice->getTransactionId());
        $this->assertEquals(21.65, $invoice->getGrandTotal());
        $this->assertEquals(2, $invoice->getTotalQty());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Stripe checks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($invoice->getTransactionId());
        $charge = $paymentIntent->charges->data[0];
        $orderIncrementId = $order->getIncrementId();
        $this->tests->compare($charge, [
            "amount" => 2165,
            "amount_captured" => 2165,
            "amount_refunded" => 0,
            "description" => "Order #$orderIncrementId by Joyce Strother",
            "metadata" => [
                "Order #" => $orderIncrementId
            ]
        ]);
    }
}
