<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeCapture\Subscriptions;

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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @dataProvider addressesProvider
     */
    public function testSubscriptionsMigration($shippingAddress, $billingAddress, $payerDetails)
    {
        $subscriptionProductToMigrate = $this->tests->helper()->loadProductBySku("simple-monthly-subscription-initial-fee-product");

        $this->expectExceptionMessage("Only one subscription is allowed per order.");
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscriptions")
            ->setShippingAddress($shippingAddress)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billingAddress)
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();
        $ordersCount = $this->tests->getOrdersCount();

        // Confirm the payment
        $paymentIntent = $this->tests->confirmCheckoutSession($order, $cart = "Subscriptions", $paymentMethod = "card", $billingAddress);

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
        $subscription = $customer->subscriptions->data[0];

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

        // No new order should be created with error: Could not migrate order #000000000: The order includes multiple subscriptions.
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Check that the old subscription has not been canceled
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);
        $this->assertEquals($customer->subscriptions->data[0]->id, $subscription->id);
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
