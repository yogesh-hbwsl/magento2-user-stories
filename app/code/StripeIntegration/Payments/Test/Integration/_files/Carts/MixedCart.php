<?php

$objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
$productRepository = $objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

$quote = $objectManager->create(\Magento\Quote\Model\Quote::class);
$quote->load('test_quote', 'reserved_order_id');

$product = $productRepository->get('simple-product');
$quote->addProduct($product, 2);

$product = $productRepository->get('simple-monthly-subscription-initial-fee-product');
$quote->addProduct($product, 2);

$quoteRepository = $objectManager->create(\Magento\Quote\Api\CartRepositoryInterface::class);
$quoteRepository->save($quote);
