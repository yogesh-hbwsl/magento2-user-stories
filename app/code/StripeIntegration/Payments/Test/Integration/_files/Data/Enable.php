<?php

$objectManager = \Magento\TestFramework\ObjectManager::getInstance();
$configResource = $objectManager->get(\Magento\Config\Model\ResourceModel\Config::class);
$configResource->saveConfig(
    'payment/stripe_payments/active',
    1,
    'stores',
    1
);
$configResource->saveConfig(
    'payment/stripe_payments_basic/stripe_mode',
    "test",
    'stores',
    1
);
$configResource->saveConfig(
    'payment/stripe_payments/webhook_origin_check',
    0,
    'stores',
    1
);

$objectManager->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
$objectManager->create(\Magento\Store\Model\StoreManagerInterface::class)->reinitStores();
