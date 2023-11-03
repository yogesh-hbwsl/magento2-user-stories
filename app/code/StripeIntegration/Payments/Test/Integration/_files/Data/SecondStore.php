<?php

$objectManager = \Magento\TestFramework\ObjectManager::getInstance();
$storeManager = $objectManager->create("\Magento\Store\Model\StoreManagerInterface");
$stores = $storeManager->getStores(true, true);
if(!isset($stores["second_store"]))
{
    $store = $objectManager->create(\Magento\Store\Model\Store::class);
    $store->setCode('second_store');
    $store->setName('Second Store');
    $store->setWebsiteId(1);
    $store->setGroupId(1);
    $store->setIsActive(1);
    $store->save();

    $objectManager->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
    $objectManager->create(\Magento\Store\Model\StoreManagerInterface::class)->reinitStores();
}
