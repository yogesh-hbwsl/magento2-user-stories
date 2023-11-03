<?php

$objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
$quoteRepository = $objectManager->create(\Magento\Quote\Api\CartRepositoryInterface::class);

$quote = $objectManager->create(\Magento\Quote\Model\Quote::class);
$quote->load('test_quote', 'reserved_order_id');

$addressData = [
    'telephone' => "917-535-4022",
    'postcode' => "10013",
    'country_id' => 'US',
    'region_id' => 10,
    'city' => 'New York',
    'street' => ['1255 Duncan Avenue'],
    'lastname' => 'Jerry',
    'firstname' => 'Flint',
    'email' => 'jerryflint@example.com',
];

$billingAddress = $objectManager->create(
    \Magento\Quote\Model\Quote\Address::class,
    ['data' => $addressData]
);

$billingAddress->setAddressType('billing');

$quote->setBillingAddress($billingAddress);
$quote->setCustomerEmail($addressData["email"]);

$quoteRepository->save($quote);
