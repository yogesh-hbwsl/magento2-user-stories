<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\SubscriptionStartDates;

use Magento\Sales\Model\Order\Invoice;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class OrderDateTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $objectManager;
    private $quote;
    private $tests;
    private $subscriptionOptionsCollectionFactory;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
        $this->subscriptionOptionsCollectionFactory = $this->objectManager->create(\StripeIntegration\Payments\Model\ResourceModel\SubscriptionOptions\CollectionFactory::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testPlaceOrder()
    {
        $product = $this->tests->getProduct('simple-monthly-subscription-product');
        $product->setSubscriptionOptions([
            'start_on_specific_date' => 1,
            'start_date' => "2021-01-10",
            'first_payment' => 'on_order_date',
            'prorate_first_payment' => 0
        ]);
        $this->tests->helper()->saveProduct($product);

        $subscriptionOptionsCollection = $this->subscriptionOptionsCollectionFactory->create();
        $subscriptionOptionsCollection->addFieldToFilter('product_id', $product->getId());
        $this->assertCount(1, $subscriptionOptionsCollection->getItems());

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $subscription = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $customerId = $subscription->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId);

        // Customer has one subscription
        $this->assertCount(1, $customer->subscriptions->data);

        // The customer has one charge for the order total
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId]);
        $this->assertCount(1, $charges->data);
        $this->assertEquals($order->getGrandTotal() * 100, $charges->data[0]->amount);

        $subscription = $customer->subscriptions->data[0];
        // Get the subscription start date
        $subscriptionStartDate = $subscription->billing_cycle_anchor;

        // The subscription start date should be today
        $this->assertEquals(date("d", time()), date("d", $subscriptionStartDate));

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
            "description" => "Subscription order #{$order->getIncrementId()} by Joyce Strother",
            "discount" => null
        ]);

        // The upcoming invoice should be on the 10th
        $upcomingInvoice = $this->tests->stripe()->invoices->upcoming([
            'customer' => $customerId,
            'subscription' => $subscription->id
        ]);
        $this->assertEquals("10", date("d", $upcomingInvoice->next_payment_attempt));

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

        // Make sure that the new order amount is the same
        $recurringOrder = $this->tests->getLastOrder();
        $this->tests->compare($recurringOrder->getData(), [
            'grand_total' => $order->getGrandTotal()
        ]);
    }
}
