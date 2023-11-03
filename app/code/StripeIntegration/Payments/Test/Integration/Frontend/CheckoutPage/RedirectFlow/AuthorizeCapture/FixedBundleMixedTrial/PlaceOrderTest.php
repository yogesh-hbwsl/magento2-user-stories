<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\FixedBundleMixedTrial;

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

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     */
    public function testFixedBundleMixedTrialCart()
    {
        $this->markTestIncomplete("Bundled subscriptions not fully supported yet");

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("FixedBundleMixedTrial")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeCheckout");

        $quote = $this->quote->getQuote();

        // Checkout totals should be correct
        $trialSubscriptionsConfig = $this->subscriptions->getTrialingSubscriptionsAmounts($quote);

        $this->assertEquals(80, $trialSubscriptionsConfig["subscriptions_total"], "Subtotal");
        $this->assertEquals(80, $trialSubscriptionsConfig["base_subscriptions_total"], "Base Subtotal");

        $this->assertEquals(20, $trialSubscriptionsConfig["shipping_total"], "Shipping");
        $this->assertEquals(20, $trialSubscriptionsConfig["base_shipping_total"], "Base Shipping");

        $this->assertEquals(0, $trialSubscriptionsConfig["discount_total"], "Discount");
        $this->assertEquals(0, $trialSubscriptionsConfig["base_discount_total"], "Base Discount");

        $this->assertEquals(6.6, $trialSubscriptionsConfig["tax_total"], "Tax");
        $this->assertEquals(6.6, $trialSubscriptionsConfig["tax_total"], "Base Tax");

        // Place the order
        $order = $this->quote->placeOrder();

        $orderIncrementId = $order->getIncrementId();
        $currency = $order->getOrderCurrencyCode();
        $expectedChargeAmount = $order->getGrandTotal()
            - $trialSubscriptionsConfig["subscriptions_total"]
            - $trialSubscriptionsConfig["shipping_total"]
            + $trialSubscriptionsConfig["discount_total"]
            - $trialSubscriptionsConfig["tax_total"];

        $expectedChargeAmount = $this->tests->helper()->convertMagentoAmountToStripeAmount($expectedChargeAmount, $currency);

        // Retrieve the created session
        $checkoutSessionId = $order->getPayment()->getAdditionalInformation('checkout_session_id');
        $this->assertNotEmpty($checkoutSessionId);

        $stripe = $this->tests->stripe();
        $session = $stripe->checkout->sessions->retrieve($checkoutSessionId);

        $this->assertEquals($expectedChargeAmount, $session->amount_total);

        // Confirm the payment
        $method = "SuccessCard";
        $session = $this->tests->checkout()->retrieveSession($order, "FixedBundleMixedTrial");
        $response = $this->tests->checkout()->confirm($session, $order, $method, "California");
        $this->tests->checkout()->authenticate($response->payment_intent, $method);
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);

        // Assert order status, amount due, invoices
        $this->assertEquals("new", $order->getState());
        $this->assertEquals("pending", $order->getStatus());
        $this->assertEquals(0, $order->getInvoiceCollection()->count());

        // Stripe subscription checks
        $customer = $stripe->customers->retrieve($session->customer);
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $this->assertEquals("trialing", $subscription->status);
        $this->assertEquals(10660, $subscription->items->data[0]->price->unit_amount);

        $subscriptionId = $subscription->id;

        // Process the charge.succeeded event
        $charge =  $paymentIntent->charges->data[0];
        $this->tests->event()->trigger("charge.succeeded", $charge);

        // Process invoice.payment_succeeded event
        $ordersCount = $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->count();
        $customer = $stripe->customers->retrieve($session->customer);
        $invoiceId = $customer->subscriptions->data[0]->latest_invoice;
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoiceId);

        // Ensure that no new order was created
        $newOrdersCount = $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->count();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Assert order status, amount due, invoices, invoice items, invoice totals
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(106.6, $order->getTotalDue());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());

        // End the trial
        $stripe->subscriptions->update($subscriptionId, ['trial_end' => "now"]);
        $subscription = $stripe->subscriptions->retrieve($subscriptionId, ['expand' => ['latest_invoice']]);

        $ordersCount = $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->count();

        // Trigger webhook events for the trial end
        $this->tests->event()->trigger("charge.succeeded", $subscription->latest_invoice->charge);

        $this->tests->event()->trigger("invoice.payment_succeeded", $subscription->latest_invoice->id);

        // Check that the order invoice was marked as paid
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals(128.25, $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalDue());
        $invoicesCollection = $order->getInvoiceCollection();
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());
        $this->assertEquals($paymentIntent->id, $invoice->getTransactionId());

        // Check that the transaction IDs have been associated with the order
        $transactions = $this->tests->helper()->getOrderTransactions($order);
        $this->assertEquals(2, count($transactions));
        foreach ($transactions as $key => $transaction)
        {
            if ($transaction->getTxnId() == $subscription->latest_invoice->payment_intent)
            {
                $this->assertEquals("capture", $transaction->getTxnType());
                $this->assertEquals(106.6, $transaction->getAdditionalInformation("amount"));
            }
            else
            {
                $this->assertEquals($paymentIntent->id, $transaction->getTxnId());
                $this->assertEquals("capture", $transaction->getTxnType());
                $this->assertEquals(21.65, $transaction->getAdditionalInformation("amount"));
            }
        }

        // Ensure that a new order was created
        $newOrdersCount = $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->count();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Check the newly created order
        $newOrder = $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->setOrder('increment_id','DESC')->getFirstItem();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("processing", $newOrder->getState());
        $this->assertEquals("processing", $newOrder->getStatus());
        $this->assertEquals(106.6, $newOrder->getGrandTotal());
        $this->assertEquals(106.6, $newOrder->getTotalPaid());
        $this->assertEquals(1, $newOrder->getInvoiceCollection()->getSize());

        // Process a recurring subscription billing webhook
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoiceId);

        // Get the newly created order
        $newOrder = $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->setOrder('entity_id','DESC')->getFirstItem();

        // Assert new order, invoices, invoice items, invoice totals
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("processing", $newOrder->getState());
        $this->assertEquals("processing", $newOrder->getStatus());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());
    }
}
