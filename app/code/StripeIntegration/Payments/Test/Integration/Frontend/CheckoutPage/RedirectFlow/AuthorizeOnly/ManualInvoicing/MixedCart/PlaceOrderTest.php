<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeOnly\ManualInvoicing\MixedCart;

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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     */
    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("MixedCart")
            ->setShippingAddress("NewYork")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("NewYork")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();
        $orderIncrementId = $order->getIncrementId();

        // Confirm the payment
        $method = "SuccessCard";
        $session = $this->tests->checkout()->retrieveSession($order);
        $response = $this->tests->checkout()->confirm($session, $order, $method, "NewYork");
        $this->tests->checkout()->authenticate($response->payment_intent, $method);
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);

        // Assert order status, amount due, invoices
        $this->assertEquals("pending_payment", $order->getState());
        $this->assertEquals("pending_payment", $order->getStatus());
        $this->assertEquals($session->amount_total / 100, round($order->getGrandTotal(), 2));
        $this->assertEquals(0, round(floatval($order->getTotalPaid()), 2));
        $this->assertEquals($session->amount_total / 100, round($order->getTotalDue(), 2));
        $this->assertEquals(0, $order->getInvoiceCollection()->count());

        // Stripe checks
        $customerId = $session->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);

        // Trigger webhooks
        // charge.succeeded & payment_intent.succeeded & invoice.payment_succeeded
        $subscription = $customer->subscriptions->data[0];
        $this->tests->event()->triggerSubscriptionEvents($subscription, $this);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Assert order status, amount due, invoices, invoice items, invoice totals
        $this->assertEquals($session->amount_total / 100, round($order->getGrandTotal(), 2));
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals($session->amount_total / 100, round($order->getTotalPaid(), 2));
        $this->assertEquals(0, round($order->getTotalDue(), 2));
        $this->assertEquals(1, $order->getInvoiceCollection()->count());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(69.86, $order->getTotalPaid());
        $this->assertFalse($order->canInvoice());

        // Assert Magento invoice
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals($order->getGrandTotal(), $invoice->getGrandTotal());
        $this->assertEquals($order->getSubTotal(), $invoice->getSubTotal());

        // Reset
        $this->tests->helper()->clearCache();

        // Stripe checks
        $paymentIntentId = $order->getPayment()->getLastTransId();
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId);
        $this->tests->compare($paymentIntent, [
            "amount" => $order->getGrandTotal() * 100,
            "description" => "Subscription order #$orderIncrementId by Flint Jerry"
        ]);

        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);

        $this->assertNotEmpty($customer->subscriptions->data[0]->latest_invoice);
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice, ['expand' => ['payment_intent']]);
        $this->tests->compare($customer->subscriptions->data[0], [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => 3168,
                            "currency" => "usd",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => 3168
                        ],
                        "quantity" => 1
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $order->getIncrementId()
            ],
            "status" => "active"
        ]);

        // Check the amounts of the latest invoice
        $this->tests->compare($invoice, [
            "amount_due" => 6986,
            "amount_paid" => 6986,
            "amount_remaining" => 0,
            "tax" => 0,
            "total" => 6986,
            "payment_intent" => [
                "amount" => 6986,
                "amount_received" => 6986,
                "charges" => [
                    "data" => [
                        0 => [
                            "amount" => 6986,
                            "amount_captured" => 6986
                        ]
                    ]
                ],
                "description" => "Subscription order #$orderIncrementId by Flint Jerry",
                "metadata" => [
                    "Order #" => $orderIncrementId
                ]
            ]
        ]);

        $upcomingInvoice = $this->tests->stripe()->invoices->upcoming(['customer' => $customer->id]);
        $this->assertCount(1, $upcomingInvoice->lines->data);
        $this->tests->compare($upcomingInvoice, [
            "tax" => 0,
            "total" => 3168
        ]);
    }
}
