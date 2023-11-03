<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeCapture\Subscription;

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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Discounts.php
     */
    public function testExpiringCouponOnce()
    {
        $this->markTestIncomplete("This works on the front-end but not in the test suite. The test needs adjustments.");

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setCouponCode("10_percent_apply_once")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();

        return;

        // Confirm the payment
        $paymentIntent = $this->tests->confirmCheckoutSession($order, "Subscription", "card", "California");

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $customerId = $paymentIntent->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId);

        //Customer has one subscription
        $this->assertCount(1, $customer->subscriptions->data);

        //The subscription setup is correct.

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
            "status" => "active"
        ]);

        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice);

        $this->compare->object($invoice, [
            "amount_due" => 0,
            "amount_paid" => $order->getGrandTotal() * 100,
            "amount_remaining" => 0,
            "total" => $order->getGrandTotal() * 100
        ]);
    }
}
