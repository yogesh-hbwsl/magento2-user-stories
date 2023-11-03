<?php

namespace StripeIntegration\Payments\Test\Integration\StripeDashboard\EmbeddedFlow\AuthorizeOnly\AutomaticInvoicing\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ElevatedRiskTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $helper;
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/automatic_invoicing 1
     */
    public function testCancelation()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("ElevatedRiskCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirm($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals(0, $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalDue());

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertTrue($invoice->canCapture());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_OPEN, $invoice->getState());

        // Cancel the payment in Stripe
        $this->assertNotEmpty($paymentIntent->review);
        $this->assertStringContainsString("prv_", $paymentIntent->review);
        $this->compare->object($paymentIntent, [
            "amount_capturable" => 5330,
            "payment_method_options" => [
                "card" => [
                    "capture_method" => "manual"
                ]
            ],
            "status" => "requires_capture"
        ]);

        $paymentIntent = $this->tests->stripe()->paymentIntents->cancel($paymentIntent->id, ["cancellation_reason" => "fraudulent"]);
        $this->tests->event()->trigger("charge.refunded", $paymentIntent->charges->data[0]);
        $this->tests->event()->trigger("review.closed", $paymentIntent->review);

        // Refresh the order object
        $this->helper->clearCache();
        $order = $this->helper->loadOrderByIncrementId($order->getIncrementId());
        $this->assertEquals("payment_review", $order->getState());
        $this->assertEquals("fraud", $order->getStatus());
        $this->assertEquals(0, $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals(53.30, $order->getTotalCanceled());
        $this->assertEquals(53.30, $order->getTotalDue());

        // Check the invoice
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_CANCELED, $invoice->getState());
    }
}
