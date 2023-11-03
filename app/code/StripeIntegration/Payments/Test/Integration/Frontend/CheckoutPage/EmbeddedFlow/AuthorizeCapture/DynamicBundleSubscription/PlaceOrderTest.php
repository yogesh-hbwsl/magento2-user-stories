<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\EmbeddedFlow\AuthorizeCapture\DynamicBundleSubscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $subscriptions;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->subscriptions = $this->objectManager->get(\StripeIntegration\Payments\Helper\Subscriptions::class);
    }

    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("DynamicBundleSubscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $quote = $this->quote->getQuote();
        $this->assertEquals(53.30, $quote->getGrandTotal());

        // Place the order
        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Assert order status, amount due, invoices
        $this->tests->compare($order->getData(), [
            "state" => "processing",
            "status" => "processing",
            "total_due" => 0,
            "total_paid" => $order->getGrandTotal(),
        ]);
        $this->assertEquals(1, $order->getInvoiceCollection()->getSize());
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        $currency = $order->getOrderCurrencyCode();

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Check that the subscription plan amount is correct
        $customer = $this->tests->helper()->getCustomerModel()->retrieveByStripeID();
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $this->tests->compare($subscription, [
            "status" => "active",
            "items" => [
                "data" => [
                    0 => [
                        "price" => [
                            "nickname" => "$53.30 every month",
                            "unit_amount" => 5330
                        ],
                        "quantity" => 1
                    ]
                ]
            ]
        ]);
        $subscriptionProductId = $subscription->plan->product;
        $subscriptionProduct = $this->tests->stripe()->products->retrieve($subscriptionProductId);
        $this->assertEquals("Bundle Dynamic", $subscriptionProduct->name);
        $expectedChargeAmountStripe = $this->tests->helper()->convertMagentoAmountToStripeAmount($order->getGrandTotal(), $currency);
        $this->assertEquals($expectedChargeAmountStripe, $subscription->plan->amount);

        // Check that the last subscription invoice matched the order total
        $latestInvoice = $this->tests->stripe()->invoices->retrieve($subscription->latest_invoice, []);
        $this->assertEquals($expectedChargeAmountStripe, $latestInvoice->amount_paid);
    }
}
