<?php

$settings = get_defined_constants();

$objectManager = \Magento\TestFramework\ObjectManager::getInstance();
$configResource = $objectManager->get(\Magento\Config\Model\ResourceModel\Config::class);

$configResource->saveConfig(
    'payment/stripe_payments_basic/mode',
    "test",
    'stores',
    1
);
$configResource->saveConfig(
    'payment/stripe_payments/active',
    1,
    'stores',
    1
);
$configResource->saveConfig(
    'payment/stripe_payments/checkout_mode',
    1,
    'stores',
    1
);
$configResource->saveConfig(
    'payment/stripe_payments/payment_action',
    "authorize",
    'stores',
    1
);
$configResource->saveConfig(
    'payment/stripe_payments/automatic_invoicing',
    1,
    'stores',
    1
);
$configResource->saveConfig(
    'payment/stripe_payments/expired_authorizations',
    1,
    'stores',
    1
);
$configResource->saveConfig(
    'payment/stripe_payments/ccsave',
    2,
    'stores',
    1
);

$objectManager->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
$objectManager->create(\Magento\Store\Model\StoreManagerInterface::class)->reinitStores();
