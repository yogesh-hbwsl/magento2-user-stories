<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeCapture\MixedTrial;

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

        // Assert order status, amount due, invoices
        $this->assertEquals("pending_payment", $order->getState());
        $this->assertEquals("pending_payment", $order->getStatus());
        $this->assertEquals(0, $order->getInvoiceCollection()->count());

        // Confirm the payment
        $paymentIntent = $this->tests->confirmCheckoutSession($order, "MixedTrial", "card", "NewYork");

        $trialSubscriptionAmount = 1346;

        // Stripe checks
        $customerId = $paymentIntent->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);
        $this->tests->compare($customer->subscriptions->data[0], [
            "status" => "trialing",
            "plan" => [
                "amount" => $trialSubscriptionAmount
            ]
        ]);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Assert order status, amount due
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        // Assert Magento invoice, invoice items, invoice totals
        $this->assertEquals(1, $order->getInvoiceCollection()->count());
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals($order->getTotalPaid(), $invoice->getGrandTotal());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());
        $this->assertEquals(8.5, $invoice->getShippingAmount());
        $this->assertEquals(10, $invoice->getBaseShippingAmount());

        // Stripe checks
        $this->assertNotEmpty($customer->subscriptions->data[0]->latest_invoice);

        $upcomingInvoice = $this->tests->stripe()->invoices->upcoming(['customer' => $customer->id]);
        $this->assertCount(1, $upcomingInvoice->lines->data);
        $this->tests->compare($upcomingInvoice, [
            "tax" => 0,
            "total" => $trialSubscriptionAmount
        ]);

        // Activate the subscription
        $ordersCount = $this->tests->getOrdersCount();
        $this->tests->endTrialSubscription($customer->subscriptions->data[0]->id);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // New order checks
        $order = $this->tests->getLastOrder();
        $this->assertEquals(15.84, $order->getBaseGrandTotal());
        if ($this->tests->magento("<", "2.4"))
            $this->assertEquals(13.59, $order->getGrandTotal()); // Magento 2.3.7-p3 does not perform a currency conversion on the tax_amount
        else
            $this->assertEquals(13.46, $order->getGrandTotal());

        $this->assertEquals(1, $order->getInvoiceCollection()->count());
    }
}
