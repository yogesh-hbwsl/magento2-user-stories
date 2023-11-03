<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

use PHPUnit\Framework\Constraint\StringContains;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class FailedPaymentTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $helper;
    private $objectManager;
    private $paymentElement;
    private $paymentIntentModel;
    private $quote;
    private $service;
    private $stripeConfig;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
        $this->paymentIntentModel = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentIntent::class);
        $this->paymentElement = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentElement::class);
        $this->service = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testUpdateCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("DeclinedCard");

        $ordersCount = $this->tests->getOrdersCount();

        try
        {
            $order = $this->quote->placeOrder();
        }
        catch (\Exception $e)
        {
            $this->assertEquals("Your card was declined.", $e->getMessage());
        }

        $newOrdersCount = $this->tests->getOrdersCount();

        $this->assertEquals($ordersCount, $newOrdersCount);

        // We change the items in the cart and the shipping address and expect that
        // the cached Payment Intent will also be updated when we retry placing the order
        $this->quote->addProduct('simple-product', 2)
            ->setShippingAddress("NewYork")
            ->setShippingMethod("FlatRate")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $this->tests->confirm($order);

        // Check the payment intent in Stripe
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($this->paymentElement->getPaymentIntent()->id, []);
        $this->tests->compare($paymentIntent, [
            'metadata' => [
                'Order #' => $order->getIncrementId()
            ]
        ]);

        $grandTotal = $order->getGrandTotal() * 100;
        $orderIncrementId = $order->getIncrementId();

        $this->compare->object($paymentIntent, [
            "amount" => $grandTotal,
            "currency" => "usd",
            "amount_received" => $grandTotal,
            "description" => "Order #$orderIncrementId by Joyce Strother",
            "charges" => [
                "data" => [
                    0 => [
                        "amount" => $grandTotal,
                        "amount_captured" => $grandTotal,
                        "amount_refunded" => 0,
                        "metadata" => [
                            "Order #" => $orderIncrementId
                        ]
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $orderIncrementId
            ],
            "shipping" => [
                "address" => [
                    "city" => "New York",
                    "country" => "US",
                    "line1" => "1255 Duncan Avenue",
                    "postal_code" => "10013",
                    "state" => "New York",
                ],
                "name" => "Flint Jerry",
                "phone" => "917-535-4022"
            ],
            "status" => "succeeded"
        ]);
    }
}
