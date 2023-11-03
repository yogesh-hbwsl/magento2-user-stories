<?php

namespace StripeIntegration\Payments\Test\Integration\Cron;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class WebhooksConfigureTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testCron()
    {
        // Disable the origin check
        $this->tests->config()->disableOriginCheck();
        $this->tests->config()->clearCache("config");
        $this->tests->reInitConfig();
        $value = $this->tests->config()->getValue("payment/stripe_payments/webhook_origin_check", "default");
        $this->assertEquals("0", $value);

        // Delete existing webhooks
        $webhooksCollection = $this->objectManager->create(\StripeIntegration\Payments\Model\ResourceModel\Webhook\Collection::class);
        $webhooksCollection->walk('delete');
        $this->assertEquals(0, $webhooksCollection->getSize());

        // Set mode to production
        $appState = $this->objectManager->get(\Magento\Framework\App\State::class);
        $appState->setMode("default");

        // Run the cron job
        $cron = $this->objectManager->create(\StripeIntegration\Payments\Cron\WebhooksConfigure::class);
        $cron->execute();
        $this->assertEmpty($cron->lastError);
        $this->tests->reInitConfig();

        // Check if an entry was created
        $webhooksCollection = $this->objectManager->create(\StripeIntegration\Payments\Model\ResourceModel\Webhook\Collection::class);
        $this->assertEquals(1, $webhooksCollection->getSize());

        // Check that the origin check was re-enabled
        $value = $this->tests->config()->getValue("payment/stripe_payments/webhook_origin_check", "default");
        $this->assertEquals("1", $value);

        // Restore values
        $appState->setMode("developer");
        $this->tests->config()->disableOriginCheck();
        $this->tests->config()->clearCache("config");
        $this->tests->reInitConfig();
    }
}
