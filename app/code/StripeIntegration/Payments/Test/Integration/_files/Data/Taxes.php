<?php

use Magento\TestFramework\Helper\Bootstrap;
use Magento\Store\Model\ScopeInterface;
use Magento\Tax\Model\ClassModel;
use Magento\Tax\Model\Config;
use Magento\Tax\Model\TaxRuleFixtureFactory;

$objectManager = Bootstrap::getObjectManager();
$taxRuleFixtureFactory = new TaxRuleFixtureFactory();

$taxRuleFixtureFactory->createTaxRules([
    [
        'code' => 'Default Rule',
        'customer_tax_class_ids' => [ \Magento\Setup\Fixtures\TaxRulesFixture::DEFAULT_CUSTOMER_TAX_CLASS_ID ],
        'product_tax_class_ids' => [ \Magento\Setup\Fixtures\TaxRulesFixture::DEFAULT_PRODUCT_TAX_CLASS_ID ],
        'tax_rate_ids' => [ 1, 2 ],
        'sort_order' => 0,
        'priority' => 0,
    ]
]);

// $taxClasses = $taxRuleFixtureFactory->createTaxClasses([
//     ['name' => 'DefaultCustomerClass', 'type' => ClassModel::TAX_CLASS_TYPE_CUSTOMER],
//     ['name' => 'DefaultProductClass', 'type' => ClassModel::TAX_CLASS_TYPE_PRODUCT],
//     ['name' => 'HigherProductClass', 'type' => ClassModel::TAX_CLASS_TYPE_PRODUCT],
// ]);

// $taxRates = $taxRuleFixtureFactory->createTaxRates([
//     ['percentage' => 7.5, 'country' => 'US', 'region' => 42],
//     ['percentage' => 7.5, 'country' => 'US', 'region' => 12], // Default store rate
// ]);

// $higherRates = $taxRuleFixtureFactory->createTaxRates([
//     ['percentage' => 22, 'country' => 'US', 'region' => 42],
//     ['percentage' => 10, 'country' => 'US', 'region' => 12], // Default store rate
// ]);

// $taxRuleFixtureFactory->createTaxRules([
//     [
//         'code' => 'Default Rule',
//         'customer_tax_class_ids' => [$taxClasses['DefaultCustomerClass'], 3],
//         'product_tax_class_ids' => [$taxClasses['DefaultProductClass']],
//         'tax_rate_ids' => array_values($taxRates),
//         'sort_order' => 0,
//         'priority' => 0,
//     ],
//     [
//         'code' => 'Higher Rate Rule',
//         'customer_tax_class_ids' => [$taxClasses['DefaultCustomerClass'], 3],
//         'product_tax_class_ids' => [$taxClasses['HigherProductClass']],
//         'tax_rate_ids' => array_values($higherRates),
//         'sort_order' => 0,
//         'priority' => 0,
//     ],
// ]);
