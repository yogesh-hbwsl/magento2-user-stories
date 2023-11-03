<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeOnly\ManualInvoicing\MixedTrial;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/automatic_invoicing 0
     */
    public function testCaptures()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart('MixedTrial')
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        $paymentIntent = $this->tests->confirmSubscription($order);

        $order = $this->tests->refreshOrder($order);

        $this->tests->compare($paymentIntent, [
            "amount" => 1583,
            "capture_method" => "automatic"
        ]);

        $this->tests->compare($order->getData(), [
            "state" => "processing",
            "status" => "processing",
            "base_total_paid" => 31.66
        ]);
    }
}
