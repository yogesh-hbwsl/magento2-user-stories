<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\SubscriptionInitialFee;

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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testSubscriptionCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("SubscriptionInitialFee")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $this->tests->startWebhooks();

        $order = $this->quote->placeOrder();

        $this->tests->runWebhooks();

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Check if Radar risk value is been set to the order
        $this->assertIsNumeric($order->getStripeRadarRiskScore());
        $this->assertGreaterThanOrEqual(0, $order->getStripeRadarRiskScore());
        $this->assertNotEmpty($order->getStripeRadarRiskLevel());

        // Check Stripe Payment method
        $paymentMethod = $this->tests->loadPaymentMethod($order->getId());
        $this->assertEquals('card', $paymentMethod->getPaymentMethodType());

        $invoicesCollection = $order->getInvoiceCollection();

        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(1, $invoicesCollection->getSize());

        $this->assertFalse($order->canInvoice());

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Stripe checks
        $orderTotal = 1908;
        $subscriptionTotal = 1583; // $10 for the item, $5 for the shipping, $0.83 for tax
        $initialFee = 325; // $3 fee + $0.25 tax

        $paymentIntentId = $order->getPayment()->getLastTransId();
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId, []);
        $this->tests->compare($paymentIntent, [
            "amount" => $orderTotal,
            "amount_received" => $orderTotal,
            "description" => $this->tests->helper()->getOrderDescription($order)
        ]);

        $customerId = $paymentIntent->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);
        $this->tests->compare($customer->subscriptions->data[0], [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => $subscriptionTotal,
                            "currency" => "usd",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => $subscriptionTotal
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

        // Check that the upcoming invoice does not include the initial fee
        $invoice = $this->tests->stripe()->invoices->upcoming([ 'customer' => $customerId ]);
        $this->tests->compare($invoice, [
            "amount_due" => $subscriptionTotal,
            "amount_paid" => 0,
            "amount_remaining" => $subscriptionTotal,
            "total" => $subscriptionTotal
        ]);
    }
}
