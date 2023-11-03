<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Trial;

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
     *
     * @magentoConfigFixture current_store general/country/allow US
     */
    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("TrialSimple")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeCheckout");

        // Place the order
        $order = $this->quote->placeOrder();
        $orderIncrementId = $order->getIncrementId();

        // Confirm the payment
        $method = "card";
        $session = $this->tests->checkout()->retrieveSession($order, "Trial");
        $response = $this->tests->checkout()->confirm($session, $order, $method, "California");

        // Wait until the subscription is creared and retrieve it
        $customerId = $response->customer->id;
        $wait = 5;
        do
        {
            $subscriptions = $this->tests->stripe()->subscriptions->all(['limit' => 3, 'customer' => $customerId]);
            if (count($subscriptions->data) > 0)
                break;
            sleep(1);
            $wait--;
        }
        while ($wait > 0);

        $this->assertCount(1, $subscriptions->data);
        $this->tests->compare($subscriptions->data[0], [
            "status" => "trialing",
            "plan" => [
                "amount" => $order->getGrandTotal() * 100
            ],
            "metadata" => [
                "Order #" => $orderIncrementId
            ]
        ]);
        $this->assertNotEmpty($subscriptions->data[0]->metadata->{"SubscriptionProductIDs"});

        $ordersCount = $this->tests->getOrdersCount();

        // Trigger charge.succeeded & payment_intent.succeeded & invoice.payment_succeeded
        $subscription = $subscriptions->data[0];
        $this->tests->event()->triggerSubscriptionEvents($subscription, $this);

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Check if Radar risk value is been set to the order
        $this->assertIsNotNumeric($order->getStripeRadarRiskScore());
        $this->assertEquals('NA', $order->getStripeRadarRiskLevel());

        // Check Stripe Payment method
        $paymentMethod = $this->tests->loadPaymentMethod($order->getId());
        $this->assertEquals('', $paymentMethod->getPaymentMethodType());

        $this->tests->compare($order->getData(), [
            "grand_total" => $order->getGrandTotal(),
            "total_paid" => $order->getGrandTotal(),
            "total_refunded" => $order->getGrandTotal(),
            "state" => "processing",
            "status" => "processing"
        ]);

        // End the trial
        $this->tests->endTrialSubscription($subscription->id);

        // Ensure that a new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        $this->tests->compare($order->getData(), [
            "grand_total" => $order->getGrandTotal(),
            "total_paid" => $order->getGrandTotal(),
            "total_refunded" => $order->getGrandTotal(),
            "state" => "processing",
            "status" => "processing"
        ]);
    }
}
