<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeOnly\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ElevatedRiskRejectedTest extends \PHPUnit\Framework\TestCase
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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     */
    public function testUnholdOrder()
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

        $invoicesCollection = $order->getInvoiceCollection();

        $this->assertEquals("holded", $order->getState());
        $this->assertEquals("holded", $order->getStatus());
        $this->assertEquals(0, $invoicesCollection->count());

        $this->tests->event()->trigger("review.closed", [
            "id" => "prv_1JDnB8HLyfDWKHBq36KwlmhZ",
            "object" => "review",
            "billing_zip" => null,
            "charge" => null,
            "closed_reason" => "refunded_as_fraud",
            "created" => 1626427074,
            "ip_address" => null,
            "ip_address_location" => null,
            "livemode" => false,
            "open" => false,
            "opened_reason" => "rule",
            "payment_intent" => $paymentIntent->id,
            "reason" => "refunded_as_fraud",
            "session" => null
        ]);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("payment_review", $order->getState());
        $this->assertEquals("fraud", $order->getStatus());
    }
}
