<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeCapture\ConfigurableSubscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SEPADebitPlaceOrderTest extends \PHPUnit\Framework\TestCase
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
        $productId = $this->tests->helper()->loadProductBySku("simple-monthly-subscription-product")->getId();

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("ConfigurableSubscription")
            ->setShippingAddress("NewYork")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("NewYork")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();
        $ordersCount = $this->tests->getOrdersCount();
        $orderIncrementId = $order->getIncrementId();

        // Confirm the payment
        $paymentIntent = $this->tests->confirmCheckoutSession($order, $cart = "ConfigurableSubscription", $paymentMethod = "sepa_debit", $address = "NewYork");

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Ensure that no new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Assert order status, amount due, invoices
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals($paymentIntent->amount / 100, round($order->getGrandTotal(), 2));
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());

        // Stripe checks
        $customerId = $paymentIntent->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);

        // Reset
        $this->tests->helper()->clearCache();

        // Stripe checks
        $this->tests->compare($customer->subscriptions->data[0], [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => $order->getGrandTotal() * 100,
                            "currency" => "eur",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => $order->getGrandTotal() * 100
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
        $this->markTestIncomplete("The invoice may be charged with a delay, so it may not be paid yet.");
        $this->assertNotEmpty($customer->subscriptions->data[0]->latest_invoice);
        $initialOrderInvoiceId = $customer->subscriptions->data[0]->latest_invoice;
        $invoice = $this->tests->stripe()->invoices->retrieve($initialOrderInvoiceId, ['expand' => ['payment_intent']]);
        $this->tests->compare($invoice, [
            "amount_due" => $order->getGrandTotal() * 100,
            "amount_paid" => $order->getGrandTotal() * 100,
            "amount_remaining" => 0,
            "tax" => 0,
            "total" => $order->getGrandTotal() * 100,
            "payment_intent" => [
                "amount" => $order->getGrandTotal() * 100,
                "amount_received" => $order->getGrandTotal() * 100,
                "charges" => [
                    "data" => [
                        0 => [
                            "amount" => $order->getGrandTotal() * 100,
                            "amount_captured" => $order->getGrandTotal() * 100
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
            "total" => $order->getGrandTotal() * 100
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
        $order = $this->tests->refreshOrder($order);
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertStringContainsString("pi_", $invoice->getTransactionId());
        $this->tests->refundOnline($invoice, ['simple-monthly-subscription-product' => 1], $baseShipping = 5);

        // Stripe checks
        $invoice = $this->tests->stripe()->invoices->retrieve($initialOrderInvoiceId, ['expand' => ['payment_intent']]);
        $this->tests->compare($invoice, [
            "payment_intent" => [
                "charges" => [
                    "data" => [
                        0 => [
                            "amount_refunded" => $order->getGrandTotal() * 100
                        ]
                    ]
                ]
            ]
        ]);
    }
}
