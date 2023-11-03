<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeCapture\MixedTrial;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RecurringOrderTest extends \PHPUnit\Framework\TestCase
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
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("MixedTrial")
            ->setShippingAddress("NewYork")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("NewYork")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();
        $orderIncrementId = $order->getIncrementId();
        $ordersCount = $this->tests->getOrdersCount();

        // Confirm the payment
        $paymentIntent = $this->tests->confirmCheckoutSession($order, "MixedTrial", "card", "NewYork");

        // Refresh the order
        $order = $this->tests->refreshOrder($order);
        $nonSubscriptionsAmount = 13.46;
        $baseNonSubscriptionsAmount = 15.84;
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        // Check the order invoices
        $invoiceCollection = $order->getInvoiceCollection();
        $invoice = $invoiceCollection->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Check that no new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Ship the order
        $this->tests->shipOrder($order->getId());

        // Refresh the order
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("complete", $order->getState());
        $this->assertEquals("complete", $order->getStatus());

        // Activate the subscription
        $session = $this->tests->getLastCheckoutSession();
        $customerId = $session->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->tests->endTrialSubscription($customer->subscriptions->data[0]->id);
        $newOrdersCount = $this->tests->getOrdersCount();

        // Check if a new order was created
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Refresh the order
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals("complete", $order->getState());
        $this->assertEquals("complete", $order->getStatus());

        // Trigger webhook events for recurring order
        $this->tests->event()->trigger("invoice.payment_succeeded", $paymentIntent->invoice, ['billing_reason' => 'subscription_cycle']);

        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 2, $newOrdersCount);

        // Refresh the order
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());

        // Get the newly created order
        $newOrder = $this->tests->getLastOrder();
        // Assert new order, invoices, invoice items, invoice totals

        $this->assertEquals($order->getBaseGrandTotal() - $baseNonSubscriptionsAmount, $newOrder->getBaseGrandTotal());
        if ($this->tests->magento("<", "2.4"))
            $this->assertEquals(13.59, $newOrder->getGrandTotal()); // Magento 2.3.7-p3 does not perform a currency conversion on the tax_amount
        else
            $this->assertEquals($order->getGrandTotal() - $nonSubscriptionsAmount, $newOrder->getGrandTotal());

        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("processing", $newOrder->getState());
        $this->assertEquals("processing", $newOrder->getStatus());
        $this->assertEquals(0, $newOrder->getTotalDue());
        $this->assertEquals(1, $newOrder->getInvoiceCollection()->getSize());
        $this->assertStringContainsString("pi_", $newOrder->getInvoiceCollection()->getFirstItem()->getTransactionId());

        // Stripe checks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntent->id);
        $this->tests->compare($paymentIntent, [
            "description" => "Recurring subscription order #{$newOrder->getIncrementId()} by Flint Jerry",
            "metadata" => [
                "Order #" => $newOrder->getIncrementId()
            ]
        ]);
    }
}
