<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SessionExpiredTest extends \PHPUnit\Framework\TestCase
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
    public function testMixedCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();

        // Assert order status, amount due, invoices
        $this->assertEquals("pending_payment", $order->getState());
        $this->assertEquals("pending_payment", $order->getStatus());

        // Retrieve the created session
        $checkoutSessionId = $order->getPayment()->getAdditionalInformation('checkout_session_id');
        $this->assertNotEmpty($checkoutSessionId);
        $session = $this->tests->stripe()->checkout->sessions->retrieve($checkoutSessionId);
        $this->assertEquals($session->amount_total / 100, round($order->getGrandTotal(), 2));
        $this->assertEquals($session->amount_total / 100, round($order->getTotalDue(), 2));
        $this->assertEquals(0, $order->getInvoiceCollection()->count());

        // Trigger a session expiration webhook
        $this->tests->event()->trigger("checkout.session.expired", $session);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Assert order status, amount due, invoices, invoice items, invoice totals
        $this->assertEquals("canceled", $order->getState());
        $this->assertEquals("canceled", $order->getStatus());
        $this->assertEquals(0, $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalCanceled());
    }
}
