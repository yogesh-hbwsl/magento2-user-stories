<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SubscriptionPriceCommandTest extends \PHPUnit\Framework\TestCase
{
    private $apiService;
    private $objectManager;
    private $quote;
    private $subscriptionPriceCommand;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->subscriptionPriceCommand = $this->objectManager->get(\StripeIntegration\Payments\Commands\Subscriptions\MigrateSubscriptionPriceCommand::class);
        $this->apiService = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testSubscriptionsMigration()
    {
        $shippingAddress = "California";
        $billingAddress = "California";
        $payerDetails = [
            'email' => 'jerryflint@example.com',
            'name' => 'Jerry Flint',
            'phone' => "917-535-4022"
        ];

        $subscriptionProductToMigrate = $this->tests->helper()->loadProductBySku("simple-monthly-subscription-initial-fee-product");
        $oldGrandTotal = 19.08; // Includes the subscription initial fee
        $newGrandTotal = 21.24; // Does not include the initial fee

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("SubscriptionInitialFee")
            ->setShippingAddress($shippingAddress)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billingAddress)
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirmSubscription($order);
        $this->assertEquals($order->getGrandTotal($oldGrandTotal), $order->getGrandTotal());

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Stripe checks
        $customerModel = $this->tests->helper()->getCustomerModel();
        $subscriptions = $customerModel->getSubscriptions();
        $this->assertCount(1, $subscriptions);
        $originalSubscription = array_pop($subscriptions);

        // Reset
        $this->tests->helper()->clearCache();

        // Change the subscription price
        $subscriptionProductToMigrate->setPrice(15);
        $subscriptionProductToMigrate = $this->tests->saveProduct($subscriptionProductToMigrate);
        $productId = $subscriptionProductToMigrate->getId();

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

        $ordersCount = $this->tests->getOrdersCount();
        $exitCode = $this->subscriptionPriceCommand->run($input, $output);
        $this->assertEquals(0, $exitCode);

        // Order checks
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->tests->compare($newOrder->getData(), [
            "state" => "closed",
            "status" => "closed",
            "grand_total" => $newGrandTotal,
            "total_invoiced" => 0
        ]);

        // Check that the old subscription has not been canceled
        $subscriptions = $customerModel->getSubscriptions();
        $this->assertCount(1, $subscriptions);

        // Stripe checks
        $newSubscription = array_pop($subscriptions);
        $this->tests->compare($newSubscription, [
            "id" => $originalSubscription['id'],
            "customer" => $originalSubscription['customer'],
            "default_payment_method" => [
                "id" => $originalSubscription['default_payment_method']["id"]
            ],
            "metadata" => [
                "Order #" => $newOrder->getIncrementId(),
                "SubscriptionProductIDs" => $subscriptionProductToMigrate->getId(),
                "Type" => "SubscriptionsTotal"
            ],
            "plan" => [
                "amount" => $newGrandTotal * 100,
                "product" => $subscriptionProductToMigrate->getId() // The Stripe product must have the same ID as the Magento product
            ],
            "status" => $originalSubscription["status"]
        ]);


    }
}
