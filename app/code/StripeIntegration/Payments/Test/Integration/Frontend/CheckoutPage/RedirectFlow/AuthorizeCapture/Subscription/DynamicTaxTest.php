<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeCapture\Subscription;

use StripeIntegration\Payments\Test\Integration\Mock\Magento\Tax\Model\Calculation as MockTaxCalculation;
use Magento\Tax\Api\TaxRateRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class DynamicTaxTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;
    private $orderHelper;
    private $address;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->orderHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Order::class);
        $this->address = $this->objectManager->get(\StripeIntegration\Payments\Test\Integration\Helper\Address::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     */
    public function testDynamicTax()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();
        $this->tests->confirmCheckoutSession($order, "Subscription", "card", "California");

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Get the order tax percent
        $appliedTaxes = $this->orderHelper->getAppliedTaxes($order->getId());
        $this->assertCount(1, $appliedTaxes);
        $this->assertEquals("8.2500", $appliedTaxes[0]['percent']);

        // Stripe checks
        $orderTotal = $order->getGrandTotal() * 100;

        $paymentIntentId = $order->getPayment()->getLastTransId();
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId, []);
        $this->tests->compare($paymentIntent, [
            "amount" => $orderTotal,
            "amount_received" => $orderTotal,
            // "description" => $this->tests->helper()->getOrderDescription($order)
        ]);

        $customerId = $paymentIntent->customer;
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $this->tests->compare($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => $orderTotal,
                            "currency" => "usd",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => $orderTotal
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

        // Change the order's shipping and billing address, so that the tax rate becomes 8.375%
        $newYorkData = $this->address->getMagentoFormat("NewYork");
        $order->getShippingAddress()->addData($newYorkData)->save();
        $order->getBillingAddress()->addData($newYorkData)->save();
        $this->tests->helper()->clearCache();

        // Trigger an invoice.upcoming webhook
        $this->tests->event()->trigger("invoice.upcoming", $subscription->latest_invoice);

        // The subscription price should now have been updated to match the new tax rate. Check if that is indeed the case
        $subscription = $this->tests->stripe()->subscriptions->retrieve($subscription->id);

        $expectedTotal = 2 * (10 + 0.84 + 5) * 100; // $10 product price, $0.84 tax, $5 shipping
        $this->tests->compare($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => $expectedTotal,
                            "currency" => "usd",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => $expectedTotal
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
    }
}
