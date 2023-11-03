<?php
use Magento\Directory\Model\Currency;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

$rates = [
    'USD' => [
        'EUR' => '0.85',
        'MXN' => '20',
        'GBP' => '0.75',
        'CAD' => '1.25',
        'MYR' => '4.2',
        'BRL' => '5.5',
        'AUD' => '1.36',
        'JPY' => '128'
    ]
];

$currencyModel = $objectManager->create(Currency::class);
$currencyModel->saveRates($rates);
