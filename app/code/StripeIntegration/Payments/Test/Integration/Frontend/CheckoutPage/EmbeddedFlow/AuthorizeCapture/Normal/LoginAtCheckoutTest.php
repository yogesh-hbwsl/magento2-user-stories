<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\SessionFactory as CheckoutSessionFactory;
use PHPUnit\Framework\Constraint\StringContains;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class LoginAtCheckoutTest extends \PHPUnit\Framework\TestCase
{
    private $api;
    private $customersCollectionFactory;
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->api = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);

        $this->customersCollectionFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\ResourceModel\StripeCustomer\CollectionFactory::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testNormalCart()
    {
        $size = $this->customersCollectionFactory->create()->getSize();
        $this->assertEquals(0, $size);

        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $size = $this->customersCollectionFactory->create()->getSize();
        $this->assertEquals(0, $size);

        $this->quote->login();

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirm($order);

        $size = $this->customersCollectionFactory->create()->getSize();
        $this->assertEquals(1, $size);
    }
}
