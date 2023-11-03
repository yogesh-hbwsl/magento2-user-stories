<?php

$settings = get_defined_constants();

$publicKey = $settings['API_PK_TEST'];
$secretKey = $settings['API_SK_TEST'];

$objectManager = \Magento\TestFramework\ObjectManager::getInstance();
$configResource = $objectManager->get(\Magento\Config\Model\ResourceModel\Config::class);
$configResource->saveConfig(
    'payment/stripe_payments_basic/stripe_test_pk',
    $publicKey,
    'stores',
    1
);
$configResource->saveConfig(
    'payment/stripe_payments_basic/stripe_test_sk',
    $secretKey,
    'stores',
    1
);

$objectManager->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
$objectManager->create(\Magento\Store\Model\StoreManagerInterface::class)->reinitStores();
