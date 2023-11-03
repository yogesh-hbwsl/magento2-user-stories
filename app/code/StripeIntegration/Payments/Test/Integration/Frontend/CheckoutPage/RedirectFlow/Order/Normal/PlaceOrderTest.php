<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\Order\Normal;

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
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();

        // Confirm the payment
        $checkoutSession = $this->tests->confirmCheckoutSession($order, "OrderOnly", "card", "California");
        $this->assertNotEmpty($checkoutSession->customer->id, "No customer set");
        $this->assertEquals("off_session", $checkoutSession->setup_intent->usage, "Usage is not off_sesssion");
        $this->assertNotEmpty($checkoutSession->setup_intent->payment_method, "No saved payment method");

        // Refresh the order
        $order = $this->tests->refreshOrder($order);
        $payment = $order->getPayment();
        $this->assertEquals($checkoutSession->customer->id, $payment->getAdditionalInformation('customer_stripe_id'));
        $this->assertEquals($checkoutSession->setup_intent->payment_method, $payment->getAdditionalInformation('token'));

        // Order checks
        $this->assertTrue($order->canEdit());
        $this->assertCount(0, $order->getInvoiceCollection());
        $this->assertEquals(0, $order->getTotalPaid());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalDue());
        $this->assertEquals("processing", $order->getStatus());
    }
}
