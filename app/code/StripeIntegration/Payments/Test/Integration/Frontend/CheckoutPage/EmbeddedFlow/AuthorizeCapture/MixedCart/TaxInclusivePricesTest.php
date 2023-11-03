<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\MixedCart;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class TaxInclusivePricesTest extends \PHPUnit\Framework\TestCase
{
    private $cartManagement;
    private $helper;
    private $objectManager;
    private $productRepository;
    private $quote;
    private $request;
    private $stripeConfig;
    private $subscriptions;
    private $webhooks;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->cartManagement = $this->objectManager->get(\Magento\Quote\Api\CartManagementInterface::class);
        $this->webhooks = $this->objectManager->get(\StripeIntegration\Payments\Helper\Webhooks::class);
        $this->request = $this->objectManager->get(\Magento\Framework\App\Request\Http::class);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->subscriptions = $this->objectManager->get(\StripeIntegration\Payments\Helper\Subscriptions::class);
        $this->productRepository = $this->objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     *
     * @magentoConfigFixture current_store customer/create_account/default_group 1
     * @magentoConfigFixture current_store customer/create_account/auto_group_assign 1
     * @magentoConfigFixture current_store tax/classes/shipping_tax_class 2
     * @magentoConfigFixture current_store tax/calculation/price_includes_tax 1
     * @magentoConfigFixture current_store tax/calculation/shipping_includes_tax 1
     * @magentoConfigFixture current_store tax/calculation/discount_tax 1
     */
    public function testMixedCart()
    {
        $calculation = $this->objectManager->get(\Magento\Tax\Model\Calculation::class);
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("MixedCart")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $orderItem = null;
        foreach ($order->getAllItems() as $item)
        {
            if ($item->getSku() == "simple-monthly-subscription-initial-fee-product")
                $orderItem = $item;
        }
        $this->assertNotEmpty($orderItem);

        $product = $this->helper->loadProductById($orderItem->getProductId());
        $subscriptionProfile = $this->subscriptions->getSubscriptionDetails($product, $order, $orderItem);

        $expectedProfile = [
            "name" => "Simple Monthly Subscription + Initial Fee",
            "qty" => 2,
            "interval" => "month",
            "interval_count" => 1,
            "amount_magento" => 10,
            "amount_stripe" => 1000,
            "initial_fee_stripe" => 600,
            "initial_fee_magento" => 6,
            "discount_amount_magento" => 0,
            "discount_amount_stripe" => 0,
            "shipping_magento" => 10,
            "shipping_stripe" => 1000,
            "currency" => "usd",
            "tax_percent" => 8.25,
            "tax_amount_item" => 1.53,
            "tax_amount_shipping" => 0.76,
            "tax_amount_initial_fee" => 0.46,
            "trial_end" => null,
            "trial_days" => 0,
            "expiring_coupon" => null
        ];

        foreach ($expectedProfile as $key => $value)
        {
            $this->assertEquals($value, $subscriptionProfile[$key], $key);
        }
    }
}
