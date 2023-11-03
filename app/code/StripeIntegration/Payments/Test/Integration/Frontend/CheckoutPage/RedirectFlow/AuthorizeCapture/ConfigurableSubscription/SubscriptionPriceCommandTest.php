<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeCapture\ConfigurableSubscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SubscriptionPriceCommandTest extends \PHPUnit\Framework\TestCase
{
    private $apiService;
    private $compare;
    private $helper;
    private $objectManager;
    private $quote;
    private $subscriptionPriceCommand;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);

        $this->subscriptionPriceCommand = $this->objectManager->get(\StripeIntegration\Payments\Commands\Subscriptions\MigrateSubscriptionPriceCommand::class);
        $this->apiService = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @dataProvider addressesProvider
     */
    public function testSubscriptionMigration($shippingAddress, $billingAddress, $payerDetails)
    {
        $magentoProduct = $this->helper->loadProductBySku("simple-monthly-subscription-product");

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("ConfigurableSubscription")
            ->setShippingAddress($shippingAddress)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billingAddress)
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();
        $ordersCount = $this->tests->getOrdersCount();

        // Confirm the payment
        $paymentIntent = $this->tests->confirmCheckoutSession($order, $cart = "ConfigurableSubscription", $paymentMethod = "card", $billingAddress);

        // Ensure that no new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Assert order status, amount due, invoices
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals($paymentIntent->amount / 100, $order->getGrandTotal(), 2);
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid(), 2);
        $this->assertEquals(1, $order->getInvoiceCollection()->count());
        $this->assertEquals(0, $order->getTotalDue());

        // Stripe checks
        $customerId = $paymentIntent->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);

        // Reset
        $this->helper->clearCache();

        // Change the subscription price
        $magentoProduct->setPrice(15);
        $magentoProduct = $this->tests->saveProduct($magentoProduct);
        $productId = $magentoProduct->getId();

        // Migrate the existing subscription to the new price
        $inputFactory = $this->objectManager->get(\Symfony\Component\Console\Input\ArgvInputFactory::class);
        $input = $inputFactory->create([
            "argv" => [
                null,
                $productId,
                $productId,
                $order->getId(),
                $order->getId()
            ]
        ]);
        $output = $this->objectManager->get(\Symfony\Component\Console\Output\ConsoleOutput::class);

        $orderCount = $this->tests->getOrdersCount();

        $exitCode = $this->subscriptionPriceCommand->run($input, $output);
        $this->assertEquals(0, $exitCode);

        // Ensure that a new order was created
        $newOrderCount = $this->tests->getOrdersCount();
        $this->assertEquals($orderCount + 1, $newOrderCount);

        // Get the new order
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertNotEquals($order->getGrandTotal(), $newOrder->getGrandTotal());

        // Stripe checks
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);

        // Stripe checks
        $subscription = $this->tests->stripe()->subscriptions->retrieve($customer->subscriptions->data[0]->id);
        $this->compare->object($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => $newOrder->getGrandTotal() * 100,
                            "currency" => "usd",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => $newOrder->getGrandTotal() * 100
                        ],
                        "quantity" => 1
                    ]
                ]
            ],
            "metadata" => [
                "Type" => "SubscriptionsTotal",
                "SubscriptionProductIDs" => $magentoProduct->getId(),
                "Order #" => $newOrder->getIncrementId()
            ],
            "status" => "active"
        ]);

        $upcomingInvoice = $this->tests->stripe()->invoices->upcoming(['customer' => $customer->id]);
        $this->assertCount(1, $upcomingInvoice->lines->data);
        $this->compare->object($upcomingInvoice, [
            "total" => $newOrder->getGrandTotal() * 100
        ]);

        // End the trial and check if a new recurring order is created
        $ordersCount = $this->tests->getOrdersCount();
        $oldOrder = $this->tests->getLastOrder();
        $this->tests->endTrialSubscription($subscription->id);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($oldOrder->getIncrementId(), $newOrder->getIncrementId());

        $this->compare->object($newOrder->getData(), [
            "state" => "processing",
            "status" => "processing",
            "grand_total" => $oldOrder->getGrandTotal(),
            "total_invoiced" => $newOrder->getGrandTotal()
        ]);
    }

    public function addressesProvider()
    {
        $data = [
            // Full address
            [
                "shippingAddress" => "California",
                "billingAddress" => "California",
                "payerDetails" => [
                    'email' => 'jerryflint@example.com',
                    'name' => 'Jerry Flint',
                    'phone' => "917-535-4022"
                ]
            ]
        ];

        return $data;
    }
}
