<?php

$objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
$quoteRepository = $objectManager->create(\Magento\Quote\Api\CartRepositoryInterface::class);

$quote = $objectManager->create(\Magento\Quote\Model\Quote::class);
$quote->load('test_quote', 'reserved_order_id');

$quote->getShippingAddress()->setShippingMethod('flatrate_flatrate')->setCollectShippingRates(true);
$quote->collectTotals();

$quoteRepository = $objectManager->create(\Magento\Quote\Api\CartRepositoryInterface::class);
$quoteRepository->save($quote);
