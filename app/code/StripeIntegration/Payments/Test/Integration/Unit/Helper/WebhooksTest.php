<?php

namespace StripeIntegration\Payments\Test\Integration\Unit\Helper;

use PHPUnit\Framework\Constraint\StringContains;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class WebhooksTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $webhooks;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->webhooks = $this->objectManager->get(\StripeIntegration\Payments\Helper\Webhooks::class);
    }

    public function testOrderLoad()
    {
        $event = [
            'id' => 'evt_test',
            'type' => 'source.chargeable',
            'data' => [
                'object' => [
                    'metadata' => [
                        'Order #' => "does_not_exist"
                    ]
                ]
            ]
        ];

        $start = time();

        $this->expectExceptionMessage("Received source.chargeable webhook with Order #does_not_exist but could not find the order in Magento.");
        $this->webhooks->loadOrderFromEvent($event);

        $end = time();
        $this->assertTrue(($end - $start) < 30, "Load order timeout");
    }
}
