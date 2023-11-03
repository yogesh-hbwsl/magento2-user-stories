<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\Multishipping\AuthorizeOnly\ManualInvoicing\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $service;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\MultishippingQuote();
        $this->service = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/automatic_invoicing 0
     */
    public function testNormalCart()
    {
        $this->quote->create()
            ->setCart("Normal")
            ->setPaymentMethod("SuccessCard");

        $ordersCount = $this->tests->getOrdersCount();

        $result = json_decode($this->service->place_multishipping_order());

        $this->assertTrue(isset($result->redirect));
        $this->assertEquals($this->tests->helper()->getUrl('multishipping/checkout/success'), $result->redirect);

        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 2, $newOrdersCount, ($newOrdersCount - $ordersCount) . " orders were placed");

        $order1 = $this->tests->getOrderBySortPosition(1);
        $order2 = $this->tests->getOrderBySortPosition(2);
        $this->assertNotEmpty($order1, "No orders were placed.");
        $this->assertNotEmpty($order2, "Only 1 order was placed.");
        $this->assertEquals($order1->getPayment()->getLastTransId(), $order2->getPayment()->getLastTransId());
        $this->assertStringStartsWith("pi_", $order1->getPayment()->getLastTransId());

        // Order 1 checks

        $this->tests->compare($order1->getData(), [
            "state" => "processing",
            "status" => "processing",
            "grand_total" => 15,
            "shipping_amount" => 5,
            "total_due" => $order1->getGrandTotal()
        ]);

        $payment = $order1->getPayment();
        $this->assertEquals("Multishipping checkout", $payment->getAdditionalInformation("payment_location"));
        $this->assertStringStartsWith("cus_", $payment->getAdditionalInformation("customer_stripe_id"));

        $transactions = $this->tests->helper()->getOrderTransactions($order1);
        $this->assertCount(1, $transactions);
        foreach ($transactions as $transaction)
        {
            $this->assertEquals("authorization", $transaction->getTxnType());
            $this->assertEquals($payment->getLastTransId(), $transaction->getTxnId());
        }

        $invoices = $order1->getInvoiceCollection();
        $this->assertCount(0, $invoices);

        // Order 2 checks

        $this->tests->compare($order2->getData(), [
            "state" => "processing",
            "status" => "processing",
            "grand_total" => 15.83,
            "shipping_amount" => 5,
            "total_due" => $order2->getGrandTotal()
        ]);

        $payment = $order2->getPayment();
        $this->assertEquals("Multishipping checkout", $payment->getAdditionalInformation("payment_location"));
        $this->assertStringStartsWith("cus_", $payment->getAdditionalInformation("customer_stripe_id"));

        $transactions = $this->tests->helper()->getOrderTransactions($order2);
        $this->assertCount(1, $transactions);
        foreach ($transactions as $transaction)
        {
            $this->assertEquals("authorization", $transaction->getTxnType());
            $this->assertEquals($payment->getLastTransId(), $transaction->getTxnId());
        }

        $invoices = $order2->getInvoiceCollection();
        $this->assertCount(0, $invoices);

        // Payment Intent checks

        $paymentIntentId = $order1->getPayment()->getLastTransId();
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId, []);

        $expected = [
            "orders_total" => ($order1->getGrandTotal() * 100 + $order2->getGrandTotal() * 100),
            "description" => "Multishipping orders #{$order2->getIncrementId()}, #{$order1->getIncrementId()} by John Smith",
            "metadata" => [
                "Cart #" => $order1->getQuoteId(),
                "Multishipping" => "Yes",
                "Orders" => "{$order2->getIncrementId()},{$order1->getIncrementId()}"
            ],
            "customer" => $order1->getPayment()->getAdditionalInformation("customer_stripe_id")
        ];

        $this->tests->compare($paymentIntent, [
            "amount" => $expected["orders_total"],
            "amount_capturable" => $expected["orders_total"],
            "capture_method" => "manual",
            "charges" => [
                "data" => [
                    0 => [
                        "description" => $expected["description"],
                        "metadata" => $expected["metadata"],
                        "customer" => $expected["customer"]
                    ]
                ]
            ],
            "description" => $expected["description"],
            "metadata" => $expected["metadata"],
            "customer" => $expected["customer"],
            "status" => "requires_capture"
        ]);

        // Capture both orders
        $invoice1 = $this->tests->invoiceOnline($order1, []);
        $this->tests->helper()->saveOrder($order1);
        $invoice2 = $this->tests->invoiceOnline($order2, []);
        $this->tests->helper()->saveOrder($order2);

        // Refresh objects
        $order1 = $this->tests->refreshOrder($order1);
        $order2 = $this->tests->refreshOrder($order2);
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId, []);

        // Check if Radar risk value is been set to the order
        $this->assertIsNumeric($order1->getStripeRadarRiskScore());
        $this->assertGreaterThanOrEqual(0, $order1->getStripeRadarRiskScore());
        $this->assertNotEquals('NA', $order1->getStripeRadarRiskLevel());

        // Check Stripe Payment method
        $paymentMethod = $this->tests->loadPaymentMethod($order1->getId());
        $this->assertEquals('', $paymentMethod->getPaymentMethodType());

        // Check if Radar risk value is been set to the order
        $this->assertIsNumeric($order2->getStripeRadarRiskScore());
        $this->assertGreaterThanOrEqual(0, $order2->getStripeRadarRiskScore());
        $this->assertNotEquals('NA', $order2->getStripeRadarRiskLevel());

        // Check Stripe Payment method
        $paymentMethod = $this->tests->loadPaymentMethod($order2->getId());
        $this->assertEquals('', $paymentMethod->getPaymentMethodType());

        $this->assertEquals($order1->getGrandTotal(), $order1->getTotalPaid());
        $this->assertEquals($order2->getGrandTotal(), $order2->getTotalPaid());
        $this->tests->compare($paymentIntent, [
            "amount" => $expected["orders_total"],
            "amount_capturable" => 0,
            "amount_received" => $expected["orders_total"],
            "status" => "succeeded"
        ]);
    }
}
