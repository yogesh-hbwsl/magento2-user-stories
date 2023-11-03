<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\ConfigurableSubscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RecurringOrdersTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $helper;
    private $quote;
    private $tests;
    private $compare;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testRecurringOrders()
    {
        $calculation = $this->objectManager->get(\Magento\Tax\Model\Calculation::class);
        $this->quote->create()
            ->setCustomer('Guest')
            ->addProduct('configurable-subscription', 10, [["subscription" => "monthly"]])
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $ordersCount = $this->tests->getOrdersCount();
        $this->tests->confirmSubscription($order);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Stripe checks
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);
        $this->compare->object($customer->subscriptions->data[0], [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => $order->getGrandTotal() * 100,
                            "currency" => "usd",
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
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice);
        $this->compare->object($invoice, [
            "amount_due" => $order->getGrandTotal() * 100,
            "amount_paid" => $order->getGrandTotal() * 100,
            "amount_remaining" => 0,
            "total" => $order->getGrandTotal() * 100
        ]);

        // Trigger webhook events for recurring order
        $this->tests->event()->trigger("charge.succeeded", $invoice->charge);
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoice->id, ['billing_reason' => 'subscription_cycle']);

        // Make sure a new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Get the recurring order
        $recurringOrder = $this->tests->getLastOrder();

        // Order checks
        $this->compare->object($recurringOrder->getData(), [
            'grand_total' => $order->getGrandTotal(),
            'shipping_amount' => $order->getShippingAmount(),
            'tax_amount' => $order->getTaxAmount(),
        ]);
    }
}
