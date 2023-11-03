<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeCapture\ConfigurableSubscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class BACSDebitPlaceOrderTest extends \PHPUnit\Framework\TestCase
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
     * @magentoConfigFixture current_store currency/options/allow GBP,USD
     * @magentoConfigFixture current_store currency/options/default GBP
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysUK.php
     */
    public function testPlaceOrder()
    {
        $productId = $this->tests->helper()->loadProductBySku("simple-monthly-subscription-product")->getId();

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("ConfigurableSubscription")
            ->setShippingAddress("London")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("London")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();
        $orderIncrementId = $order->getIncrementId();

        // Confirm the payment
        $method = "bacs_debit";
        $session = $this->tests->checkout()->retrieveSession($order);
        $response = $this->tests->checkout()->confirm($session, $order, $method, "London");
        $this->tests->checkout()->authenticate($response->payment_intent, $method);
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);

        // Assert order status, amount due, invoices
        $this->assertEquals("pending_payment", $order->getState());
        $this->assertEquals("pending_payment", $order->getStatus());
        $this->assertEquals($session->amount_total / 100, round($order->getGrandTotal(), 2));
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
        // $subscription = $customer->subscriptions->data[0];
        // $this->tests->event()->triggerSubscriptionEvents($subscription);
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
                            "amount" => "1125",
                            "currency" => "gbp",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => "1125"
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
            "amount_due" => 1125,
            "tax" => 0,
            "total" => 1125,
            "payment_intent" => [
                "amount" => 1125
            ]
        ]);

        $upcomingInvoice = $this->tests->stripe()->invoices->upcoming(['customer' => $customer->id]);
        $this->assertCount(1, $upcomingInvoice->lines->data);
        $this->tests->compare($upcomingInvoice, [
            "tax" => 0,
            "total" => 1125
        ]);
    }
}
