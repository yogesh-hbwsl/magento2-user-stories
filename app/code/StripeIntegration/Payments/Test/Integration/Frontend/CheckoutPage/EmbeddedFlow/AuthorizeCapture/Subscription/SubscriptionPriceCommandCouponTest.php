<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SubscriptionPriceCommandCouponTest extends \PHPUnit\Framework\TestCase
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

        $this->subscriptionPriceCommand = $this->objectManager->get(\StripeIntegration\Payments\Test\Integration\Mock\StripeIntegration\Payments\Commands\Subscriptions\MigrateSubscriptionPriceCommand::class);
        $this->apiService = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Discounts.php
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

        $subscriptionProductToMigrate = $this->tests->helper()->loadProductBySku("simple-monthly-subscription-product");
        $oldGrandTotal = 15.83; // Includes the subscription initial fee
        $newGrandTotal = 21.65; // Does not include the initial fee

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscription")
            ->setShippingAddress($shippingAddress)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billingAddress)
            ->setCouponCode("10_percent_for_3months")
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

        $this->expectExceptionMessage("This subscription cannot be changed because it's upcoming invoice includes a discount coupon.");
        $exitCode = $this->subscriptionPriceCommand->run($input, $output);
    }
}
