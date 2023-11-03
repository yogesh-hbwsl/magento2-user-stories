<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\TrialSimple;

use PHPUnit\Framework\Constraint\StringContains;

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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testPlaceOrder()
    {
        $this->tests->startWebhooks();

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("TrialSimple")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        $this->tests->runWebhooks();

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Check if Radar risk value is been set to the order
        $this->assertIsNotNumeric($order->getStripeRadarRiskScore());
        $this->assertEquals('NA', $order->getStripeRadarRiskLevel());

        // Check Stripe Payment method
        $paymentMethod = $this->tests->loadPaymentMethod($order->getId());
        $this->assertEquals('', $paymentMethod->getPaymentMethodType());

        // Assert order status, amount due
        $this->tests->compare($order->getData(), [
            "total_paid" => 15.83,
            "total_due" => 0,
            "total_refunded" => 15.83,
            "total_canceled" => 0,
            "state" => "processing",
            "status" => "processing"
        ]);

        $this->assertTrue($order->canShip());
        $this->assertFalse($order->canCreditmemo());

        // Activate the subscription
        $ordersCount = $this->tests->getOrdersCount();
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->tests->endTrialSubscription($customer->subscriptions->data[0]->id);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Stripe checks
        $this->assertNotEmpty($customer->subscriptions->data[0]->latest_invoice);
        $this->tests->compare($customer->subscriptions->data[0]->plan, [
            "amount" => 1583
        ]);

        $upcomingInvoice = $this->tests->stripe()->invoices->upcoming(['customer' => $customer->id]);
        $this->assertCount(1, $upcomingInvoice->lines->data);
        $this->tests->compare($upcomingInvoice, [
            "tax" => 0,
            "total" => 1583
        ]);

        // Process a recurring subscription billing webhook
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice);
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoice, ['billing_reason' => 'subscription_cycle']);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 2, $newOrdersCount);

        // Get the newly created order
        $newOrder = $this->tests->getLastOrder();

        // Assert new order, invoices, invoice items, invoice totals
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("processing", $newOrder->getState());
        $this->assertEquals("processing", $newOrder->getStatus());
        $this->assertEquals($order->getGrandTotal(), $newOrder->getGrandTotal());
        $this->assertEquals(0, $newOrder->getTotalDue());
        $this->assertEquals(1, $newOrder->getInvoiceCollection()->getSize());
        $this->assertStringContainsString("pi_", $newOrder->getInvoiceCollection()->getFirstItem()->getTransactionId());

        // Stripe checks
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice, ['expand' => ['payment_intent']]);

        $this->tests->compare($invoice, [
            "payment_intent" => [
                "description" => "Recurring subscription order #{$newOrder->getIncrementId()} by Joyce Strother",
                "metadata" => [
                    "Order #" => $newOrder->getIncrementId()
                ]
            ]
        ]);
    }
}
