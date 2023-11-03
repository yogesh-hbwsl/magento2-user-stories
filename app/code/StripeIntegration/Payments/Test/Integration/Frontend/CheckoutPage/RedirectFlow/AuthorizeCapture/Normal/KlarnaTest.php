<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class KlarnaTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $tests;
    private $quote;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow GBP,USD
     * @magentoConfigFixture current_store currency/options/default GBP
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysUK.php
     */
    public function testPlaceOrder()
    {
        $this->markTestIncomplete("We must create a payment method for klarna correctly at Test/Integration/Helper/Checkout.php::createPaymentMethod");
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("London")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("London")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();
        $orderIncrementId = $order->getIncrementId();

        // Assert order status, amount due, invoices
        $this->assertEquals("new", $order->getState());
        $this->assertEquals("pending", $order->getStatus());
        $this->assertEquals(0, round($order->getTotalPaid(), 2));
        $this->assertEquals(0, $order->getInvoiceCollection()->getSize());

        // Confirm the payment
        $this->tests->confirmCheckoutSession($order, "Normal", "klarna", "London");
        $session = $this->tests->getLastCheckoutSession();

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Assert order status, amount due, invoices
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals($session->amount_total / 100, round($order->getGrandTotal(), 2));
        $this->assertEquals(0, round($order->getTotalDue(), 2));
        $this->assertEquals($session->amount_total / 100, round($order->getTotalPaid(), 2));
        $this->assertEquals(1, $order->getInvoiceCollection()->getSize());
    }
}
