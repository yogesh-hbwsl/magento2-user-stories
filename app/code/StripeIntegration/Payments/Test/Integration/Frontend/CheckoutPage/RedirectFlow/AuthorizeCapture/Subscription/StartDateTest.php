<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Subscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class StartDateTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     * @magentoConfigFixture current_store payment/stripe_payments/save_payment_method 0
     */
    public function testPlaceOrder()
    {
        $product = $this->tests->getProduct('simple-monthly-subscription-product');
        $product->setSubscriptionOptions([
            'start_on_specific_date' => 1,
            'start_date' => "2021-01-10",
            'first_payment' => 'on_start_date',
            'prorate_first_payment' => 0
        ]);
        $this->tests->helper()->saveProduct($product);

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();

        // Confirm the payment
        $order->setHasStartDate(true);
        $this->markTestIncomplete("This works on the front-end but not in the test suite. The test needs adjustments.");
        $this->tests->confirmCheckoutSession($order, "Subscription", "card", "California");

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertCount(1, $order->getInvoiceCollection());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals("processing", $order->getStatus());

        // Invoice checks
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals($order->getGrandTotal(), $invoice->getGrandTotal());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());
    }
}
