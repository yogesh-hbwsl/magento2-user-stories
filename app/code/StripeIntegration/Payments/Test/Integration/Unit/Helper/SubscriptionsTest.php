<?php

namespace StripeIntegration\Payments\Test\Integration\Unit\Helper;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SubscriptionsTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $helper;
    private $objectManager;
    private $quote;
    private $tests;

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
    public function testGetSubscriptionDetails()
    {
        $subscriptionsHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Subscriptions::class);

        $this->quote->create()
            ->setCustomer('Guest')
            ->addProduct('configurable-subscription', 10, [["subscription" => "monthly"]])
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $quote = $this->quote->getQuote();

        foreach ($quote->getAllItems() as $quoteItem)
        {
            $this->assertNotEmpty($quoteItem->getProduct()->getId());
            $product = $this->tests->helper()->loadProductById($quoteItem->getProduct()->getId());

            $subscriptionOption = $this->tests->loadSubscriptionOptions($product->getId());
            if (!$subscriptionOption->getSubEnabled())
                continue;

            $profile = $subscriptionsHelper->getSubscriptionDetails($product, $quote, $quoteItem);

            $this->tests->compare($profile, [
                "name" => "Configurable Subscription",
                "qty" => 10,
                "interval" => "month",
                "amount_magento" => 10,
                "amount_stripe" => 1000,
                "shipping_magento" => 50,
                "shipping_stripe" => 5000,
                "currency" => "usd",
                "tax_percent" => 8.25,
                "tax_percent_shipping" => 0,
                "tax_amount_item" => 8.25,
                "tax_amount_item_stripe" => 825
            ]);
        }
    }
}
