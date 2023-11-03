<?php

namespace StripeIntegration\Payments\Test\Integration\Unit\Model;

use PHPUnit\Framework\Constraint\StringContains;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PaymentIntentTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $paymentElement;
    private $paymentIntentModel;
    private $paymentIntentModelFactory;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->paymentIntentModel = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentIntent::class);
        $this->paymentIntentModelFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentIntentFactory::class);
        $this->paymentElement = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentElement::class);
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * In the browser, this can be tested with a Google Pay 3D Secure payment from the product page.
     * Radar needs to be configured to always trigger 3DS for all cards.
     */
    public function testRegulatoryCard()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California");

        // Check if it can be loaded from cache
        $quote = $this->quote->getQuote();
        $paymentMethod = $this->quote->createPaymentMethodFrom('4242424242424242');
        $params = $this->paymentIntentModel->getParamsFrom($quote, null, $paymentMethod->id);
        $model1 = $this->paymentIntentModelFactory->create();
        $paymentIntent = $model1->create($params, $quote, null);
        $this->assertNotEmpty($paymentIntent);

        // Simulate a client side 3DS confirmation
        $confirmParams = [
            "payment_method" => $paymentMethod->id,
            "return_url" => "http://example.com"
        ];
        $result = $model1->confirm($paymentIntent, $confirmParams);
        $this->assertEquals("succeeded", $result->status);

        // Load attempt 2 after resubmission for server side confirmation
        $model2 = $this->paymentIntentModelFactory->create();
        $paymentIntent2 = $model2->loadFromCache($params, $quote, null);
        $this->assertNotEmpty($paymentIntent2);
        $this->assertEquals($result->id, $paymentIntent2->id);
    }

    // It should be possible to get subscription params without a quote
    public function testGetParamsFrom()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("SubscriptionInitialFee")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        $params = $this->paymentIntentModel->getParamsFrom(null, $order, null);

        $this->assertNotEmpty($params["customer"]);
        $this->assertNotEmpty($params["payment_method"]);

        $this->tests->compare($params, [
            "amount" => 325, // Initial fee + tax
            "currency" => "usd",
            "description" => "Subscription order #{$order->getIncrementId()} by Joyce Strother",
            "metadata" => [
                "Order #" => $order->getIncrementId()
            ],
            "shipping" => [
                "address" => [
                    "line1" => "2974 Providence Lane",
                    "city" => "Mira Loma",
                    "country" => "US",
                    "postal_code" => "91752",
                    "state" => "California"
                ],
                "name" => "Joyce Strother",
                "phone" => "626-945-7637"
            ]
        ]);
    }
}
