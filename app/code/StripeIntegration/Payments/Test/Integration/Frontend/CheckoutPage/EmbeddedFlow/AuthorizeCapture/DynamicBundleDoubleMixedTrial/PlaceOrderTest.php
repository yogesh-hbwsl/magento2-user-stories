<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\EmbeddedFlow\AuthorizeCapture\DynamicBundleDoubleMixedTrial;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $subscriptions;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->subscriptions = $this->objectManager->get(\StripeIntegration\Payments\Helper\Subscriptions::class);
    }

    public function testDynamicBundleDoubleMixedTrialCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("DynamicBundleDoubleMixedTrial")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $quote = $this->quote->getQuote();

        // Checkout totals should be correct
        $trialSubscriptionsConfig = $this->subscriptions->getTrialingSubscriptionsAmounts($quote);

        // 4 subscriptions x $5 (50% off special price) + 4 products x $5 ($0% off)
        $this->assertEquals(40, $trialSubscriptionsConfig["subscriptions_total"], "Subtotal");
        $this->assertEquals(40, $trialSubscriptionsConfig["base_subscriptions_total"], "Base Subtotal");

        // 4 subscriptions x $5 and 50% off special price
        $this->assertEquals(10, $trialSubscriptionsConfig["shipping_total"], "Shipping");
        $this->assertEquals(10, $trialSubscriptionsConfig["base_shipping_total"], "Base Shipping");

        $this->assertEquals(0, $trialSubscriptionsConfig["discount_total"], "Discount");
        $this->assertEquals(0, $trialSubscriptionsConfig["base_discount_total"], "Base Discount");

        $this->assertEquals(3.3, $trialSubscriptionsConfig["tax_total"], "Tax");
        $this->assertEquals(3.3, $trialSubscriptionsConfig["tax_total"], "Base Tax");

        // Place the order
        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $ordersCount = $this->tests->getOrdersCount();

        // Assert order status, amount due, invoices
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());

        $orderIncrementId = $order->getIncrementId();
        $currency = $order->getOrderCurrencyCode();

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Check that the subscription plan amount is correct
        $customer = $this->tests->helper()->getCustomerModel()->retrieveByStripeID();
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $this->assertEquals("trialing", $subscription->status);
        $trialSubscriptionTotal = $trialSubscriptionsConfig["subscriptions_total"]
            + $trialSubscriptionsConfig["shipping_total"]
            - $trialSubscriptionsConfig["discount_total"]
            + $trialSubscriptionsConfig["tax_total"];
        $expectedChargeAmount = $order->getGrandTotal() - $trialSubscriptionTotal;
        $expectedChargeAmountStripe = $this->tests->helper()->convertMagentoAmountToStripeAmount($expectedChargeAmount, $currency);
        $trialSubscriptionTotalStripe = $this->tests->helper()->convertMagentoAmountToStripeAmount($trialSubscriptionTotal, $currency);
        $this->assertEquals($trialSubscriptionTotalStripe, $subscription->plan->amount);

        // Check that the last subscription invoice matched the order total
        $latestInvoice = $this->tests->stripe()->invoices->retrieve($subscription->latest_invoice, []);
        $this->assertEquals($expectedChargeAmountStripe, $latestInvoice->amount_paid);

        $subscriptionId = $subscription->id;

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Assert order status, amount due, invoices, invoice items, invoice totals
        $this->tests->compare($order->getData(), [
            "state" => "processing",
            "status" => "processing",
            "total_due" => 0,
            "total_paid" => $order->getGrandTotal(),
            "total_refunded" => $trialSubscriptionTotal
        ]);
        $this->assertEquals(1, $order->getInvoiceCollection()->getSize());
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Credit memos check
        $this->assertEquals(1, $order->getCreditmemosCollection()->getSize());
        $creditmemo = $order->getCreditmemosCollection()->getFirstItem();
        $this->assertEquals($trialSubscriptionTotal, $creditmemo->getGrandTotal());

        // End the trial
        $this->tests->endTrialSubscription($subscriptionId);
        $order = $this->tests->refreshOrder($order);

        // Check that a new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Check the newly created order
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->tests->compare($newOrder->getData(), [
            "state" => "processing",
            "status" => "processing",
            "grand_total" => $trialSubscriptionTotal,
            "total_due" => 0,
            "total_paid" => $newOrder->getGrandTotal(),
            "total_refunded" => 0,
            "shipping_amount" => 10
        ]);
        $this->assertEquals(1, $newOrder->getInvoiceCollection()->getSize());

        // Process a recurring subscription billing webhook
        $subscription = $this->tests->stripe()->subscriptions->retrieve($subscriptionId, []);
        $this->tests->event()->trigger("invoice.payment_succeeded", $subscription->latest_invoice, ['billing_reason' => 'subscription_cycle']);

        // Get the newly created order
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->tests->compare($newOrder->getData(), [
            "state" => "processing",
            "status" => "processing",
            "grand_total" => $trialSubscriptionTotal,
            "total_due" => 0,
            "total_paid" => $newOrder->getGrandTotal(),
            "total_refunded" => 0,
            "shipping_amount" => 10
        ]);
        $this->assertEquals(1, $order->getInvoiceCollection()->count());
    }
}
