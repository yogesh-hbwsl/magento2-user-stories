<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeCapture\MixedCart;

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
        $this->assertEquals(0, round($order->getTotalPaid() ? $order->getTotalPaid() : 0, 2));
        $this->assertEquals($session->amount_total / 100, round($order->getTotalDue(), 2));
        $this->assertEquals(0, $order->getInvoiceCollection()->count());

        // Stripe checks
        $customerId = $session->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);

        $ordersCount = $this->tests->getOrdersCount();

        // Trigger webhooks
        // 1. customer.created
        // 2. payment_method.attached
        // 3. payment_intent.created
        // 4. customer.updated
        // 5. invoice.created
        // 6. invoice.finalized
        // 7. customer.subscription.created
        // 8 & 9 & 10. charge.succeeded & payment_intent.succeeded & invoice.payment_succeeded
        $subscription = $customer->subscriptions->data[0];
        $this->tests->event()->triggerSubscriptionEvents($subscription, $this);
        // 11. invoice.updated
        // 12. checkout.session.completed
        // 13. customer.subscription.updated
        // 14. invoice.paid

        // Ensure that no new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

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

        // Assert Magento invoice
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals($order->getGrandTotal(), $invoice->getGrandTotal());
        $this->assertEquals($order->getSubTotal(), $invoice->getSubTotal());

        // Reset
        $this->tests->helper()->clearCache();

        // Stripe checks
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);

        // Stripe checks
        $this->assertNotEmpty($customer->subscriptions->data[0]->latest_invoice);
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice, ['expand' => ['payment_intent']]);
        $this->tests->compare($customer->subscriptions->data[0], [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => "3168",
                            "currency" => "usd",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => "3168"
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

        // Process a recurring subscription billing webhook
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoice->id, ['billing_reason' => 'subscription_cycle']);

        // Get the newly created order
        $newOrder = $this->tests->getLastOrder();

        // Assert new order, invoices, invoice items, invoice totals
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("processing", $newOrder->getState());
        $this->assertEquals("processing", $newOrder->getStatus());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(1, $order->getInvoiceCollection()->getSize());
        $this->assertStringContainsString("pi_", $order->getInvoiceCollection()->getFirstItem()->getTransactionId());

        // Stripe checks
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice, ['expand' => ['payment_intent']]);
        $this->tests->compare($invoice, [
            "payment_intent" => [
                "description" => "Recurring subscription order #{$newOrder->getIncrementId()} by Flint Jerry",
                "metadata" => [
                    "Order #" => $newOrder->getIncrementId()
                ]
            ]
        ]);

        // Refund the original order
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertStringContainsString("pi_", $invoice->getTransactionId());
        $this->tests->refundOnline($invoice, ['simple-monthly-subscription-initial-fee-product' => 1], $baseShipping = 5);

        // Stripe checks
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice, ['expand' => ['payment_intent']]);
        $this->tests->compare($invoice, [
            "payment_intent" => [
                "charges" => [
                    "data" => [
                        0 => [
                            "amount_refunded" => 1884
                        ]
                    ]
                ]
            ]
        ]);
    }
}
