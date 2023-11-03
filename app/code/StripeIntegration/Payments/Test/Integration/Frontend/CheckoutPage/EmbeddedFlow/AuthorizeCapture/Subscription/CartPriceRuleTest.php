<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

use Magento\Sales\Model\Order\Invoice;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CartPriceRuleTest extends \PHPUnit\Framework\TestCase
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

    public function createCartPriceRule()
    {
        // Create an instance of the sales rule
        /** @var \Magento\SalesRule\Model\Rule $rule */
        $rule = $this->objectManager->create(\Magento\SalesRule\Model\Rule::class);

        // Define the rule data
        $rule->setName('50% Off Cart Price Rule')
            ->setDescription('50% discount on the entire cart.')
            ->setIsActive(1)
            ->setStopRulesProcessing(0)
            ->setIsAdvanced(1)
            ->setProductIds('')
            ->setSortOrder(1)
            ->setSimpleAction(\Magento\SalesRule\Model\Rule::BY_PERCENT_ACTION)
            ->setDiscountAmount(50)  // 50% discount
            ->setDiscountQty(null)
            ->setDiscountStep(0)
            ->setSimpleFreeShipping('0')
            ->setApplyToShipping('0')
            ->setIsRss(0)
            ->setWebsiteIds([1])    // Assuming you're working with the main website. Adjust if necessary.
            ->setCustomerGroupIds([0, 1, 2, 3])  // Applying to all customer groups. Adjust if necessary.
            ->setCouponType(\Magento\SalesRule\Model\Rule::COUPON_TYPE_NO_COUPON);

        // Save the rule
        $rule->save();

        return $rule;
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/origin_check 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Discounts.php
     */
    public function testPlaceOrder()
    {
        $rule = $this->createCartPriceRule();

        // Expiring coupons should be ignored when a cart price rule has no discount coupon, so we add a temporary entry to test this
        $couponModel = $this->objectManager->create(\StripeIntegration\Payments\Model\Coupon::class);
        $couponModel->setRuleId($rule->getId());
        $couponModel->setCouponDuration($couponModel::COUPON_REPEATING);
        $couponModel->setCouponMonths(3);
        $couponModel->save();

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId);

        //Customer has one subscription
        $this->assertCount(1, $customer->subscriptions->data);

        //The subscription setup is correct.
        $subscription = $customer->subscriptions->data[0];
        $this->compare->object($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ]
                        ],
                        "quantity" => 1
                    ]
                ]
            ],
            "plan" => [
                "amount" => 3165 // The full order amount without the discount
            ],
            "metadata" => [
                "Order #" => $order->getIncrementId()
            ],
            "status" => "active",
            "discount" => [
                "coupon" => [
                    "amount_off" => 1082
                ]
            ]
        ]);

        // There should be a single discounted charge
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId]);
        $this->assertCount(1, $charges->data);

        // 2 x $10 for the subscription = 20
        // - $10 discount @ 50% = $10
        // x 8.25% tax = $10.83
        // + 2 x $5 shipping = $20.83
        $this->tests->compare($charges->data[0], [
            "amount_captured" => $order->getGrandTotal() * 100
        ]);
    }
}
