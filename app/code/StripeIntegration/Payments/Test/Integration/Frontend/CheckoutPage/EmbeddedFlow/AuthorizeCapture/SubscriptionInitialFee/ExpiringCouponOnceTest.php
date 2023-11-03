<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\SubscriptionInitialFee;

use Magento\Sales\Model\Order\Invoice;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ExpiringCouponOnceTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Discounts.php
     */
    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("SubscriptionInitialFee")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setCouponCode("10_percent_apply_once")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId);

        //Customer has one subscription
        $this->assertCount(1, $customer->subscriptions->data);

        //The subscription setup is correct.
        $subscription = $customer->subscriptions->data[0];
        $this->compare->object($customer->subscriptions->data[0], [
            "items" => [
                "data" => [
                    0 => [
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                        ],
                        "quantity" => 1
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $order->getIncrementId()
            ],
            "status" => "active",
            "discount" => null // Because the coupon expires after the first payment
        ]);

        // There should be a single discounted charge
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId]);
        $this->assertCount(1, $charges->data);

        // $10 for the subscription
        // - $1 discount @ 10% = $9
        // + $3 initial fee = $12
        // x 8.25% tax = $12.99
        // + $5 shipping = $17.99
        $this->tests->compare($charges->data[0], [
            "amount_captured" => 1799
        ]);

        // The upcoming invoice should not have any discount
        $upcomingInvoice = $this->tests->stripe()->invoices->upcoming(['customer' => $customer->id]);

        $this->assertNull($upcomingInvoice->discount);

        // $10 for the subscription
        // x 8.25% tax = $10.83
        // + $5 shipping = $15.83
        $expectedCharge = 1583;
        $this->compare->object($upcomingInvoice, [
            "amount_due" => $expectedCharge,
            "amount_paid" => 0,
            "amount_remaining" => $expectedCharge,
            "total" => $expectedCharge
        ]);

        // Trigger the next subscription payment immediately
        $subscription = $this->tests->stripe()->subscriptions->update($subscription->id, [
            'billing_cycle_anchor' => 'now',
            'proration_behavior' => "none",
            'expand' => ['latest_invoice']
        ]);

        // Create a recurring order
        $ordersCount = $this->tests->getOrdersCount();
        $this->tests->event()->trigger("invoice.payment_succeeded", $subscription->latest_invoice, [
            'billing_reason' => 'subscription_cycle'
        ]);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Make sure that the discount and initial fee has been removed from the order
        $recurringOrder = $this->tests->getLastOrder();
        $this->tests->compare($recurringOrder->getData(), [
            'grand_total' => $expectedCharge / 100
        ]);
    }
}
