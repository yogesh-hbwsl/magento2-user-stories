<?php

namespace StripeIntegration\Payments\Test\Integration\CLI;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class OriginCheckCommandTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $scopeConfigFactory;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->scopeConfigFactory = $this->objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterfaceFactory::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testOriginCheck()
    {
        $enabled = !!$this->scopeConfigFactory->create()->getValue("payment/stripe_payments/webhook_origin_check", "default", 0);

        $command = $this->objectManager->create(\StripeIntegration\Payments\Commands\Webhooks\OriginCheckCommand::class);

        $inputFactory = $this->objectManager->get(\Symfony\Component\Console\Input\ArgvInputFactory::class);
        $input = $inputFactory->create([
            "argv" => [
                null, // arg 0
                ( $enabled ? "0" : "1" ) // arg 1
            ]
        ]);
        $output = $this->objectManager->get(\Symfony\Component\Console\Output\ConsoleOutput::class);

        $exitCode = $command->run($input, $output);

        $this->objectManager->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
        $this->objectManager->create(\Magento\Store\Model\StoreManagerInterface::class)->reinitStores();

        $newValue = !!$this->scopeConfigFactory->create()->getValue("payment/stripe_payments/webhook_origin_check", "default", 0);
        $this->assertEquals(!$enabled, $newValue);
        $this->assertEquals(0, $exitCode);
    }
}
