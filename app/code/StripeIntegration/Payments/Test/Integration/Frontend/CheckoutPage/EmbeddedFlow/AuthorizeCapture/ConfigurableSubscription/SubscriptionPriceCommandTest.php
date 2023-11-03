<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\ConfigurableSubscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SubscriptionPriceCommandTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $helper;
    private $objectManager;
    private $productRepository;
    private $quote;
    private $stockRegistry;
    private $subscriptionPriceCommand;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->stockRegistry = $this->objectManager->get(\Magento\CatalogInventory\Model\StockRegistry::class);
        $this->productRepository = $this->objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
        $this->subscriptionPriceCommand = $this->objectManager->get(\StripeIntegration\Payments\Commands\Subscriptions\MigrateSubscriptionPriceCommand::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testSubscriptionMigration()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("ConfigurableSubscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $this->assertEquals(15.83, $order->getGrandTotal());
        $ordersCount = $this->tests->getOrdersCount();
        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Stripe checks
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $magentoProduct = $this->tests->helper()->loadProductBySku("simple-monthly-subscription-product");
        $this->compare->object($subscription, [
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
                "Type" => "SubscriptionsTotal",
                "SubscriptionProductIDs" => $magentoProduct->getId()
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

        // Reset
        $this->helper->clearCache();

        // Change the subscription price
        $this->assertNotEmpty($customer->subscriptions->data[0]->metadata->{"SubscriptionProductIDs"});
        $productId = $customer->subscriptions->data[0]->metadata->{"SubscriptionProductIDs"};
        $product = $this->helper->loadProductById($productId);
        $productId = $product->getEntityId();
        $product->setPrice(15);
        $product = $this->tests->saveProduct($product);

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

        $exitCode = $this->subscriptionPriceCommand->run($input, $output);
        $this->assertEquals(0, $exitCode);

        // Order checks
        $newOrdersCount = $this->tests->getOrdersCount();

        $this->assertEquals($ordersCount + 1, $newOrdersCount);
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());

        // Stripe checks
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $subscription = $customer->subscriptions->data[0];
        $this->compare->object($customer->subscriptions->data[0], [
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
            "status" => "active",
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
}
