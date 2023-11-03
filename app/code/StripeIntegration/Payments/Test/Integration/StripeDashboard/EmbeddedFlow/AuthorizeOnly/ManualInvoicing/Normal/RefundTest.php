<?php

namespace StripeIntegration\Payments\Test\Integration\StripeDashboard\EmbeddedFlow\AuthorizeOnly\ManualInvoicing\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RefundTest extends \PHPUnit\Framework\TestCase
{
    private $helper;
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testPartialRefund()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirm($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $orderIncrementId = $order->getIncrementId();

        // Partially refund the charge
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
        $this->assertEquals("closed", $order->getStatus()); // Not closed because the credit memo does not include the order items which were refunded. Those can still be shipped.
    }
}
