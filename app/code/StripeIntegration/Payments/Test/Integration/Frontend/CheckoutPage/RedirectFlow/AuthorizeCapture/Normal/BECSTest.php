<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class BECSTest extends \PHPUnit\Framework\TestCase
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
     * @magentoConfigFixture current_store currency/options/allow AUD,USD
     * @magentoConfigFixture current_store currency/options/default AUD
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysAU.php
     */
    public function testPlaceOrderAndMultipleMagentoRefunds()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("Australia")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("Australia")
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();

        $order = $this->quote->placeOrder();

        $lastCheckoutSession = $this->tests->getLastCheckoutSession();
        $customer = $this->tests->getStripeCustomer();
        $this->assertEmpty($customer);

        $this->tests->compare($lastCheckoutSession, [
            "amount_total" => $order->getGrandTotal() * 100,
            "payment_intent" => [
                "amount" => $order->getGrandTotal() * 100,
                "capture_method" => "automatic",
                "description" => "Order #" . $order->getIncrementId() . " by Declan Kidman",
                "setup_future_usage" => "unset",
                "customer" => "unset"
            ],
            "customer_email" => "declan@example.com",
            "submit_type" => "pay"
        ]);

        // Order checks
        $this->assertEquals("pending_payment", $order->getState());
        $this->assertEquals("pending_payment", $order->getStatus());
        $this->assertEquals(0, $order->getInvoiceCollection()->count());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalDue());

        // Confirm the payment
        $session = $this->tests->checkout()->retrieveSession($order);
        $response = $this->tests->checkout()->confirm($session, $order, "au_becs_debit", "Australia");
        $this->tests->checkout()->authenticate($response->payment_intent, "au_becs_debit");

        // Trigger webhooks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);
        $this->tests->event()->triggerPaymentIntentEvents($paymentIntent);

        // Stripe checks
        $orderIncrementId = $order->getIncrementId();
        $billingAddress = $this->tests->address()->getStripeFormat("Australia");
        $shippingAddress = $this->tests->address()->getStripeShippingFormat("Australia");
        $expectedValues = [
            "amount" => $order->getGrandTotal() * 100,
            "amount_capturable" => 0,
            "capture_method" => "automatic",
            "charges" => [
                "data" => [
                    0 => [
                        "amount" => $order->getGrandTotal() * 100,
                        "amount_captured" => $order->getGrandTotal() * 100,
                        "amount_refunded" => 0,
                        "billing_details" => $billingAddress,
                        "captured" => 1,
                        "currency" => "aud",
                        "description" => "Order #$orderIncrementId by Declan Kidman",
                        "metadata" => [
                            "Order #" => "$orderIncrementId",
                        ],
                        "payment_method_details" => [
                            "type" => "au_becs_debit",
                        ],
                        "shipping" => $shippingAddress,
                    ],
                ],
            ],
            "confirmation_method" => "automatic",
            "currency" => "aud",
            "description" => "Order #$orderIncrementId by Declan Kidman",
            "metadata" => [
                "Order #" => "$orderIncrementId",
            ],
            "shipping" => $shippingAddress,
        ];

        $this->tests->compare($paymentIntent, $expectedValues);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals($session->amount_total / 100, round($order->getGrandTotal(), 2));
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals($session->amount_total / 100, round($order->getTotalPaid(), 2));
        $this->assertEquals(1, $order->getInvoiceCollection()->getSize());
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

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
        $this->assertEquals($order->getGrandTotal(), $order->getTotalInvoiced());

        $this->assertEquals(50, $order->getBaseTotalPaid());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());

        $this->assertEquals(0, $order->getBaseTotalDue());
        $this->assertEquals(0, $order->getTotalDue());

        $this->assertEquals(15, $order->getBaseTotalRefunded());
        $this->assertEquals(20.4, $order->getTotalRefunded());

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
                        "amount" => 6800,
                        "amount_captured" => 6800,
                        "amount_refunded" => 2040,
                        "refunds" => [
                            "data" => [
                                0 => [
                                    "amount" => 2040,
                                    "currency" => "aud",
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
        $this->assertEquals($order->getGrandTotal(), $order->getTotalInvoiced());

        $this->assertEquals(50, $order->getBaseTotalPaid());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());

        $this->assertEquals(50, $order->getBaseTotalRefunded());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());

        $this->assertFalse($order->canCreditmemo());
        $this->assertEquals("closed", $order->getState());
        $this->assertEquals("closed", $order->getStatus());

        // Stripe checks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($response->payment_intent->id);
        $this->tests->compare($paymentIntent, [
            "charges" => [
                "data" => [
                    0 => [
                        "amount" => 6800,
                        "amount_captured" => 6800,
                        "amount_refunded" => 6800
                    ]
                ]
            ]
        ]);
    }
}
