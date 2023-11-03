<?php

$objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
$quoteRepository = $objectManager->create(\Magento\Quote\Api\CartRepositoryInterface::class);

$quote = $objectManager->create(\Magento\Quote\Model\Quote::class);
$quote->load('test_quote', 'reserved_order_id');

$data = [
    'method' => 'stripe_payments',
    'additional_data' => [
        "cc_stripejs_token" => "pm_card_riskLevelElevated:visa:4242"
    ]
];
$quote->getPayment()->importData($data);

$quoteRepository = $objectManager->create(\Magento\Quote\Api\CartRepositoryInterface::class);
$quoteRepository->save($quote);
