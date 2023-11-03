<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class APMPlaceOrderAndRefundTest extends \PHPUnit\Framework\TestCase
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
     * @dataProvider paymentMethodProvider
     */
    public function testPlaceOrderAndMultipleMagentoRefunds($payment_method_type, $billing_address, $shipping_address, $pending)
    {
        if (in_array($payment_method_type, ['p24', 'ideal', 'bancontact', 'sofort']))
            $this->markTestIncomplete("Exception: URL https://pm-redirects.stripe.com/authorize/acct_xxx/pa_nonce_xxx does not include an /authenticate endpoint");

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress($shipping_address)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billing_address)
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();

        $this->tests->assertCheckoutSessionsCountEquals(1);

        $order = $this->quote->placeOrder();

        // Ensure that we re-used the cached session from the api
        $this->tests->assertCheckoutSessionsCountEquals(1);

        $lastCheckoutSession = $this->tests->getLastCheckoutSession();
        $customer = $this->tests->getStripeCustomer();
        $this->assertEmpty($customer);

        $this->tests->compare($lastCheckoutSession, [
            "amount_total" => $order->getGrandTotal() * 100,
            "payment_intent" => [
                "amount" => $order->getGrandTotal() * 100,
                "capture_method" => "automatic",
                "description" => "Order #" . $order->getIncrementId() . " by Mario Osterhagen",
                "setup_future_usage" => "unset",
                "customer" => "unset"
            ],
            "customer_email" => "osterhagen@example.com",
            "submit_type" => "pay"
        ]);

        // Order checks
        $this->assertEquals("new", $order->getState());
        $this->assertEquals("pending", $order->getStatus());
        $this->assertEquals(0, $order->getInvoiceCollection()->count());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalDue());

        // Confirm the payment
        $session = $this->tests->checkout()->retrieveSession($order);
        $response = $this->tests->checkout()->confirm($session, $order, $payment_method_type, $billing_address);
        $this->tests->checkout()->authenticate($response->payment_intent, $payment_method_type);

        if ($payment_method_type == "sepa_debit")
            sleep(4); // Wait for the PI to switch from Processing to Succeeded

        // Trigger webhooks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);
        $this->tests->event()->triggerPaymentIntentEvents($paymentIntent);

        // Stripe checks
        $orderIncrementId = $order->getIncrementId();
        $billingAddress = $this->tests->address()->getStripeFormat($billing_address);
        $shippingAddress = $this->tests->address()->getStripeShippingFormat($shipping_address);
        $expectedValues = [
            "amount" => 4250,
            "amount_capturable" => 0,
            "amount_received" => 4250,
            "capture_method" => "automatic",
            "charges" => [
                "data" => [
                    0 => [
                        "amount" => 4250,
                        "amount_captured" => 4250,
                        "amount_refunded" => 0,
                        "billing_details" => $billingAddress,
                        "captured" => 1,
                        "currency" => "eur",
                        "description" => "Order #$orderIncrementId by Mario Osterhagen",
                        "metadata" => [
                            "Order #" => "$orderIncrementId",
                        ],
                        "paid" => 1,
                        "payment_method_details" => [
                            "type" => $payment_method_type,
                        ],
                        "shipping" => $shippingAddress,
                        "status" => "succeeded",
                    ],
                ],
            ],
            "confirmation_method" => "automatic",
            "currency" => "eur",
            "description" => "Order #$orderIncrementId by Mario Osterhagen",
            "metadata" => [
                "Order #" => "$orderIncrementId",
            ],
            "shipping" => $shippingAddress,
            "status" => "succeeded"
        ];

        if ($pending)
        {
            $expectedValues["amount_received"] = 0;
            $expectedValues["charges"]["data"][0]["paid"] = false;
            $expectedValues["charges"]["data"][0]["status"] = "pending";
            $expectedValues["status"] = "processing";
        }

        $this->tests->compare($paymentIntent, $expectedValues);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals($session->amount_total / 100, round($order->getGrandTotal(), 2));

        if ($pending)
        {
            return;

            // $this->assertEquals("new", $order->getState());
            // $this->assertEquals("pending", $order->getStatus());
            // $this->assertEquals(42.50, $order->getTotalDue());
            // $this->assertEquals(0, round($order->getTotalPaid(), 2));
            // $this->assertEquals(0, $order->getInvoiceCollection()->getSize());
        }
        else
        {
            $this->assertEquals("processing", $order->getState());
            $this->assertEquals("processing", $order->getStatus());
            $this->assertEquals(0, $order->getTotalDue());
            $this->assertEquals($session->amount_total / 100, round($order->getTotalPaid(), 2));
            $this->assertEquals(1, $order->getInvoiceCollection()->getSize());
            $invoice = $order->getInvoiceCollection()->getFirstItem();
            $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());
        }

        //////////////////////// PARTIAL REFUND ////////////////////////

        // Refund the order
        $this->assertTrue($order->canCreditmemo());
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->tests->refundOnline($invoice, ['simple-product' => 1], $baseShipping = 5);

        // Trigger webhooks
        $this->tests->event()->trigger("charge.refunded", $paymentIntent->charges->data[0]->id);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->count());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Order checks
        $this->assertEquals(50, $order->getBaseTotalInvoiced());
        $this->assertEquals(42.50, $order->getTotalInvoiced());

        $this->assertEquals(50, $order->getBaseTotalPaid());
        $this->assertEquals(42.50, $order->getTotalPaid());

        $this->assertEquals(0, $order->getBaseTotalDue());
        $this->assertEquals(0, $order->getTotalDue());

        $this->assertEquals(15, $order->getBaseTotalRefunded());
        $this->assertEquals(12.75, $order->getTotalRefunded());

        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertTrue($order->canCreditmemo());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        // Stripe checks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);
        $this->tests->compare($paymentIntent, [
            "charges" => [
                "data" => [
                    0 => [
                        "amount" => 4250,
                        "amount_captured" => 4250,
                        "amount_refunded" => 1275,
                        "refunds" => [
                            "data" => [
                                0 => [
                                    "amount" => 1275,
                                    "currency" => "eur",
                                    "status" => "pending"
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        //////////////////////// FULL REFUND ////////////////////////

        // Refund the remaining amount
        $this->tests->refundOnline($invoice, ['simple-product' => 1, 'virtual-product' => 2], $baseShipping = 5);

        // Trigger webhooks
        $this->tests->event()->trigger("charge.refunded", $paymentIntent->charges->data[0]->id);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->count());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Order checks
        $this->assertEquals(50, $order->getBaseTotalInvoiced());
        $this->assertEquals(42.50, $order->getTotalInvoiced());

        $this->assertEquals(50, $order->getBaseTotalPaid());
        $this->assertEquals(42.50, $order->getTotalPaid());

        $this->assertEquals(50, $order->getBaseTotalRefunded());
        $this->assertEquals(42.50, $order->getTotalRefunded());

        $this->assertFalse($order->canCreditmemo());
        $this->assertEquals("closed", $order->getState());
        $this->assertEquals("closed", $order->getStatus());

        // Stripe checks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);
        $this->tests->compare($paymentIntent, [
            "charges" => [
                "data" => [
                    0 => [
                        "amount" => 4250,
                        "amount_captured" => 4250,
                        "amount_refunded" => 4250
                    ]
                ]
            ]
        ]);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     * @dataProvider paymentMethodProvider
     */
    public function testFullRefundFromStripeDashboard($payment_method_type, $billing_address, $shipping_address, $pending)
    {
        if ($pending)
            return;

        if (in_array($payment_method_type, ['p24', 'ideal', 'bancontact']))
            $this->markTestIncomplete("Exception: URL https://pm-redirects.stripe.com/authorize/acct_xxx/pa_nonce_xxx does not include an /authenticate endpoint");

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress($shipping_address)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billing_address)
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();

        // Confirm the payment
        $session = $this->tests->checkout()->retrieveSession($order);
        $response = $this->tests->checkout()->confirm($session, $order, $payment_method_type, $billing_address);
        $this->tests->checkout()->authenticate($response->payment_intent, $payment_method_type);

        // Trigger webhooks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);
        $this->tests->event()->triggerPaymentIntentEvents($paymentIntent);

        // Fully refund the payment
        $this->tests->stripe()->refunds->create(['payment_intent' => $paymentIntent->id, 'amount' => $order->getGrandTotal() * 100]);

        // Trigger webhooks
        $this->tests->event()->trigger("charge.refunded", $paymentIntent->charges->data[0]->id);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals("closed", $order->getState());
        $this->assertEquals("closed", $order->getStatus());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());
        $this->assertFalse($order->canCreditmemo());

        // Invoice checks
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Credit memo checks
        $creditMemos = $order->getCreditmemosCollection();
        $this->assertCount(1, $creditMemos);
        $creditMemo = $creditMemos->getFirstItem();
        $this->assertEquals($order->getGrandTotal(), $creditMemo->getGrandTotal());
        $this->assertEquals($order->getBaseGrandTotal(), $creditMemo->getBaseGrandTotal());
    }


    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     * @dataProvider paymentMethodProvider
     */
    public function testPartialRefundFromStripeDashboard($payment_method_type, $billing_address, $shipping_address, $pending)
    {
        if ($pending)
            return;

        if (in_array($payment_method_type, ['p24', 'ideal', 'bancontact']))
            $this->markTestIncomplete("Exception: URL https://pm-redirects.stripe.com/authorize/acct_xxx/pa_nonce_xxx does not include an /authenticate endpoint");

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress($shipping_address)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billing_address)
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();

        // Confirm the payment
        $session = $this->tests->checkout()->retrieveSession($order);
        $response = $this->tests->checkout()->confirm($session, $order, $payment_method_type, $billing_address);
        $this->tests->checkout()->authenticate($response->payment_intent, $payment_method_type);

        // Trigger webhooks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);
        $this->tests->event()->triggerPaymentIntentEvents($paymentIntent);

        // Partially refund the payment
        $this->tests->stripe()->refunds->create(['payment_intent' => $paymentIntent->id, 'amount' => 1000]);

        // Trigger webhooks
        $this->tests->event()->trigger("charge.refunded", $paymentIntent->charges->data[0]->id);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals(10, $order->getTotalRefunded());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());
        $this->assertTrue($order->canCreditmemo());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        // Invoice checks
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Credit memo checks
        $creditMemos = $order->getCreditmemosCollection();
        $this->assertCount(1, $creditMemos);
        $creditMemo = $creditMemos->getFirstItem();
        $this->assertEquals(10, $creditMemo->getGrandTotal());
        $this->assertEquals(11.7600, $creditMemo->getBaseGrandTotal());

        // Fully refund the payment
        $this->tests->stripe()->refunds->create(['payment_intent' => $paymentIntent->id, 'amount' => ($order->getGrandTotal() * 100) - 1000]);

        // Trigger webhooks
        $this->tests->event()->trigger("charge.refunded", $paymentIntent->charges->data[0]->id);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());
        $this->assertFalse($order->canCreditmemo());

        $this->assertEquals("closed", $order->getState());
        $this->assertEquals("closed", $order->getStatus());

        // Invoice checks
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Credit memo checks
        $creditMemos = $order->getCreditmemosCollection();
        $totalRefunded = $baseTotalRefunded = 0;
        foreach ($creditMemos as $memo)
        {
            $totalRefunded += $memo->getGrandTotal();
            $baseTotalRefunded += $memo->getBaseGrandTotal();
        }
        $this->assertCount(2, $creditMemos);
        $this->assertEquals($order->getGrandTotal(), $totalRefunded);
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());
        $this->assertEquals($order->getBaseGrandTotal(), $baseTotalRefunded);
    }

    public function paymentMethodProvider()
    {
        return [
            [
                "payment_method_type" => "bancontact",
                "billing_address" => "Berlin",
                "shipping_address" => "Berlin",
                "pending" => false
            ],
            // @todo EPS has a different redirect url format https://pm-redirects.stripe.com/authorize/acct_xxxxx/pa_nonce_xxxxx
            // [
            //     "payment_method_type" => "eps",
            //     "billing_address" => "Berlin",
            //     "shipping_address" => "Berlin",
            //     "pending" => false
            // ],
            // @todo Giropay has a different redirect url format https://pm-redirects.stripe.com/authorize/acct_xxxxx/pa_nonce_xxxxx
            // [
            //     "payment_method_type" => "giropay",
            //     "billing_address" => "Berlin",
            //     "shipping_address" => "Berlin",
            //     "pending" => false
            // ],
            [
                "payment_method_type" => "ideal",
                "billing_address" => "Berlin",
                "shipping_address" => "Berlin",
                "pending" => false
            ],
            [
                "payment_method_type" => "p24",
                "billing_address" => "Berlin",
                "shipping_address" => "Berlin",
                "pending" => false
            ],
            [
                "payment_method_type" => "sofort",
                "billing_address" => "Berlin",
                "shipping_address" => "Berlin",
                "pending" => true
            ],
            // SEPA cannot be tested because the PI is created in Processing status and immediately switches to Succeeded status
            // Testing for either Processing or Succeeded produces random results at random runs.
            // [
            //     "payment_method_type" => "sepa_debit",
            //     "billing_address" => "Berlin",
            //     "shipping_address" => "Berlin",
            //     "pending" => false
            // ],
        ];
    }
}
