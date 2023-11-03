<?php

namespace StripeIntegration\Payments\Test\Integration\Cron\AuthorizeOnly\AutomaticInvoicing;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CronTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $tests;
    private $quote;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/automatic_invoicing 1
     */
    public function testCron()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("AuthenticationRequiredCard");

        $order = $this->quote->placeOrder();
        $this->assertEquals("pending_payment", $order->getState());
        $this->assertEquals("pending_payment", $order->getStatus());

        $cron = $this->objectManager->create(\StripeIntegration\Payments\Cron\WebhooksPing::class);
        $cache = $this->objectManager->create(\Magento\Framework\App\CacheInterface::class);

        // Test canceling abandoned orders
        $canceledPaymentIntents = $cron->cancelAbandonedPayments(0, 1);
        foreach ($canceledPaymentIntents as $paymentIntent)
        {
            if ($paymentIntent->metadata->{"Order #"} == $order->getIncrementId())
            {
                $this->tests->event()->trigger("payment_intent.canceled", $paymentIntent);
            }
        }

        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("canceled", $order->getState());
        $this->assertEquals("canceled", $order->getStatus());

        // Check
        $cron->clearStaleData();
    }
}
