<?php

include_once dirname(__FILE__) . '/../Helper/Product.php';

use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Eav\Model\Config;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

$websiteRepository = $objectManager->get(\Magento\Store\Api\WebsiteRepositoryInterface::class);
$baseWebsite = $websiteRepository->get('base');

$storeManager = $objectManager->get(StoreManagerInterface::class);
$productRepository = $objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$categoryFactory = $objectManager->get(CategoryInterfaceFactory::class);
$eavConfig = $objectManager->get(\Magento\Eav\Model\Config::class);

try
{
    $anyProduct = $productRepository->get("simple-product");
    return;
}
catch (\Exception $e)
{
    // No products yet

    $option = [
        'value' => [
            'none' => ['None'],
            'monthly' => ['Monthly'],
            'quarterly' => ['Every 3 months']
        ],
        'order' => [
            'none' => 1,
            'monthly' => 2,
            'quarterly' => 3
        ],
    ];

    // Create a subscription attribute
    $subscriptionAttributeData = [
        'type'                  => 'varchar',
        'label'                 => 'Subscription',
        'input'                 => 'select',
        'sort_order'            => 110,
        'global'                => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
        'group'                 => "Default",
        'apply_to'              => "simple,virtual",
        'is_used_in_grid'       => false,
        'is_visible_in_grid'    => false,
        'is_filterable_in_grid' => false,
        'used_for_promo_rules'  => false,
        'required'              => false,
        'user_defined'          => true,
        'visible'               => true,
        'option'                => $option,
    ];

    $setup = $objectManager->get(\Magento\Framework\Setup\ModuleDataSetupInterface::class);
    $attributeRepository = $objectManager->get(\Magento\Eav\Api\AttributeRepositoryInterface::class);
    $categorySetupFactory = $objectManager->get(\Magento\Catalog\Setup\CategorySetupFactory::class);
    $categorySetup = $categorySetupFactory->create(['setup' => $setup]);
    $categorySetup->addAttribute(\Magento\Catalog\Model\Product::ENTITY, "subscription", $subscriptionAttributeData);

    $attribute = $eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, 'subscription');
    $attributeRepository->save($attribute);
    $categorySetup->addAttributeToGroup('catalog_product', 'Default', 'General', $attribute->getId());
}

// Defaults
$defaultAttributeSetId = $objectManager->get(Config::class)->getEntityType(Product::ENTITY)->getDefaultAttributeSetId();
$defaultStoreId = $storeManager->getDefaultStoreView()->getId();
$defaultWebsiteIds = [$baseWebsite->getId()];

$productInterfaceFactory = $objectManager->get(ProductInterfaceFactory::class);

$product = $productInterfaceFactory->create();
$product->setTypeId(Type::TYPE_SIMPLE)
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Simple Product')
    ->setSku('simple-product')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->setSubscription("none")
    ->save();

$simpleProduct = $productRepository->save($product);

$product = $productInterfaceFactory->create();
$product->setTypeId(Type::TYPE_VIRTUAL)
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Virtual Product')
    ->setSku('virtual-product')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->setSubscription("none")
    ->save();

$virtualProduct = $productRepository->save($product);

$product = $productInterfaceFactory->create();
$product->setTypeId(Type::TYPE_VIRTUAL)
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Free Product')
    ->setSku('free-product')
    ->setPrice(0)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->save();

$freeProduct = $productRepository->save($product);

$product = $productInterfaceFactory->create();
$product->setTypeId(Type::TYPE_SIMPLE)
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Simple Monthly Subscription')
    ->setSku('simple-monthly-subscription-product')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->setSubscription("monthly")
    ->save();

$simpleMonthlySubscription = $productRepository->save($product);

$data = [
    'product_id' => $simpleMonthlySubscription->getId(),
    'sub_enabled' => 1,
    'sub_interval' => 'month',
    'sub_interval_count' => 1,
    'sub_trial' => 0,
    'sub_initial_fee' => 0
];
saveSubscriptionOption($data);

$product = $productInterfaceFactory->create();
$product->setTypeId(Type::TYPE_SIMPLE)
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Simple Quarterly Subscription')
    ->setSku('simple-quarterly-subscription-product')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->setSubscription("quarterly")
    ->save();

$simpleQuarterlySubscription = $productRepository->save($product);

$data = [
    'product_id' => $simpleQuarterlySubscription->getId(),
    'sub_enabled' => 1,
    'sub_interval' => 'month',
    'sub_interval_count' => 3,
    'sub_trial' => 0,
    'sub_initial_fee' => 0
];
saveSubscriptionOption($data);

$product = $productInterfaceFactory->create();
$product->setTypeId(Type::TYPE_SIMPLE)
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Simple Monthly Subscription + Initial Fee')
    ->setSku('simple-monthly-subscription-initial-fee-product')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->save();

$simpleMonthlySubscriptionInitialFee = $productRepository->save($product);

$data = [
    'product_id' => $simpleMonthlySubscriptionInitialFee->getId(),
    'sub_enabled' => 1,
    'sub_interval' => 'month',
    'sub_interval_count' => 1,
    'sub_trial' => 0,
    'sub_initial_fee' => 3
];
saveSubscriptionOption($data);

$product = $productInterfaceFactory->create();
$product->setTypeId(Type::TYPE_SIMPLE)
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Simple Trial Monthly Subscription')
    ->setSku('simple-trial-monthly-subscription-product')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->save();

$simpleTrialMonthlySubscription = $productRepository->save($product);

$data = [
    'product_id' => $simpleTrialMonthlySubscription->getId(),
    'sub_enabled' => 1,
    'sub_interval' => 'month',
    'sub_interval_count' => 1,
    'sub_trial' => 14,
    'sub_initial_fee' => 0
];
saveSubscriptionOption($data);

$product = $productInterfaceFactory->create();
$product->setTypeId(Type::TYPE_SIMPLE)
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Simple Trial Monthly Subscription + Initial Fee')
    ->setSku('simple-trial-monthly-subscription-initial-fee')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->save();

$simpleTrialMonthlySubscriptionInitialFee = $productRepository->save($product);

$data = [
    'product_id' => $simpleTrialMonthlySubscriptionInitialFee->getId(),
    'sub_enabled' => 1,
    'sub_interval' => 'month',
    'sub_interval_count' => 1,
    'sub_trial' => 14,
    'sub_initial_fee' => 3
];
saveSubscriptionOption($data);

$product = $productInterfaceFactory->create();
$product->setTypeId(Type::TYPE_VIRTUAL)
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Virtual Monthly Subscription')
    ->setSku('virtual-monthly-subscription-product')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->setSubscription("monthly")
    ->save();

$virtualMonthlySubscription = $productRepository->save($product);

$data = [
    'product_id' => $virtualMonthlySubscription->getId(),
    'sub_enabled' => 1,
    'sub_interval' => 'month',
    'sub_interval_count' => 1,
    'sub_trial' => 0,
    'sub_initial_fee' => 0
];
saveSubscriptionOption($data);

$product = $productInterfaceFactory->create();
$product->setTypeId(Type::TYPE_VIRTUAL)
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Virtual Quarterly Subscription')
    ->setSku('virtual-quarterly-subscription-product')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->setSubscription("quarterly")
    ->save();

$virtualQuarterlySubscription = $productRepository->save($product);

$data = [
    'product_id' => $virtualQuarterlySubscription->getId(),
    'sub_enabled' => 1,
    'sub_interval' => 'month',
    'sub_interval_count' => 3,
    'sub_trial' => 0,
    'sub_initial_fee' => 0
];
saveSubscriptionOption($data);

$product = $productInterfaceFactory->create();
$product->setTypeId(Type::TYPE_VIRTUAL)
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Virtual Trial Monthly Subscription')
    ->setSku('virtual-trial-monthly-subscription-product')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->save();

$virtualTrialMonthlySubscription = $productRepository->save($product);

$data = [
    'product_id' => $virtualTrialMonthlySubscription->getId(),
    'sub_enabled' => 1,
    'sub_interval' => 'month',
    'sub_interval_count' => 1,
    'sub_trial' => 14,
    'sub_initial_fee' => 0
];
saveSubscriptionOption($data);

$product = $productInterfaceFactory->create();
$product->setTypeId(Type::TYPE_VIRTUAL)
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Virtual Trial Monthly Subscription + Initial Fee')
    ->setSku('virtual-monthly-subscription-initial-fee-product')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->save();

$virtualTrialMonthlySubscriptionInitialFee = $productRepository->save($product);

$data = [
    'product_id' => $virtualTrialMonthlySubscriptionInitialFee->getId(),
    'sub_enabled' => 1,
    'sub_interval' => 'month',
    'sub_interval_count' => 1,
    'sub_trial' => 14,
    'sub_initial_fee' => 14
];
saveSubscriptionOption($data);

// ----------------------------------------------------------------------------

$bundleProduct = $objectManager->create(\Magento\Catalog\Api\Data\ProductInterface::class);
$bundleProduct
        ->setAttributeSetId($defaultAttributeSetId)
        ->setStoreId($defaultStoreId)
        ->setWebsiteIds($defaultWebsiteIds)
        ->setTypeId('bundle')
        ->setSkuType(0) // 0 - dynamic, 1 - fixed
        ->setSku('bundle-dynamic')
        ->setName('Bundle Dynamic')
        ->setWeightType(0) // 0 - dynamic, 1 - fixed
//        ->setWeight(4.0000)
        ->setShipmentType(0) // 0 - together, 1 - separately
        ->setStatus(1) // 1 - enabled, 2 - disabled
        ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
        ->setPriceType(0) // 0 - dynamic, 1 - fixed
//        ->setPrice(20)
        ->setPriceView(0) // 0 - price range, 1 - as low as
        ->setSpecialPrice(50) // percentage of original price
        ->setTaxClassId(2) // 0 - none, 1 - default, 2 - taxable, 4 - shipping
        ->setStockData(['use_config_manage_stock' => 0]);

// Set bundle product items
$bundleProduct->setBundleOptionsData(
    [
        [
            'title' => 'Regular Product',
            'default_title' => 'Regular Product',
            'type' => 'select',
            'required' => 0,
            'delete' => '',
        ],
        [
            'title' => 'Subscription',
            'default_title' => 'Subscription',
            'type' => 'select',
            'required' => 1,
            'delete' => '',
        ]
    ]
)->setBundleSelectionsData(
    [
        [
            ['product_id' => $simpleProduct->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => ''],
            ['product_id' => $virtualProduct->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => ''],
            ['product_id' => $freeProduct->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => ''],
        ],
        [
            ['product_id' => $simpleMonthlySubscription->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => ''],
            ['product_id' => $simpleQuarterlySubscription->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => ''],
            ['product_id' => $simpleMonthlySubscriptionInitialFee->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => ''],
            ['product_id' => $simpleTrialMonthlySubscription->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => ''],
            ['product_id' => $simpleTrialMonthlySubscriptionInitialFee->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => ''],
            ['product_id' => $virtualMonthlySubscription->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => ''],
            ['product_id' => $virtualTrialMonthlySubscription->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => ''],
            ['product_id' => $virtualTrialMonthlySubscriptionInitialFee->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => ''],
        ],
    ]
);
setBundleProductItems($bundleProduct);


// ----------------------------------------------------------------------------

$bundleProduct = $objectManager->create(\Magento\Catalog\Api\Data\ProductInterface::class);
$bundleProduct
        ->setAttributeSetId($defaultAttributeSetId)
        ->setStoreId($defaultStoreId)
        ->setWebsiteIds($defaultWebsiteIds)
        ->setTypeId('bundle')
        ->setSkuType(0) // 0 - dynamic, 1 - fixed
        ->setSku('bundle-fixed')
        ->setName('Bundle Fixed')
        ->setWeightType(0) // 0 - dynamic, 1 - fixed
//        ->setWeight(4.0000)
        ->setShipmentType(0) // 0 - together, 1 - separately
        ->setStatus(1) // 1 - enabled, 2 - disabled
        ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
        ->setPriceType(1) // 0 - dynamic, 1 - fixed
//        ->setPrice(20)
        ->setPriceView(0) // 0 - price range, 1 - as low as
        ->setSpecialPrice(50) // percentage of original price
        ->setTaxClassId(2) // 0 - none, 1 - default, 2 - taxable, 4 - shipping
        ->setStockData(['use_config_manage_stock' => 0]);

// Set bundle product items
$bundleProduct->setBundleOptionsData(
    [
        [
            'title' => 'Regular Product',
            'default_title' => 'Regular Product',
            'type' => 'select',
            'required' => 0,
            'delete' => '',
        ],
        [
            'title' => 'Subscription',
            'default_title' => 'Subscription',
            'type' => 'select',
            'required' => 1,
            'delete' => '',
        ]
    ]
)->setBundleSelectionsData(
    [
        [
            ['product_id' => $simpleProduct->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => '', 'selection_price_type' => 0, 'price' => 20],
            ['product_id' => $virtualProduct->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => '', 'selection_price_type' => 0, 'price' => 20],
            ['product_id' => $freeProduct->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => '', 'selection_price_type' => 0, 'price' => 0],
        ],
        [
            ['product_id' => $simpleMonthlySubscription->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => '', 'selection_price_type' => 0, 'price' => 20],
            ['product_id' => $simpleQuarterlySubscription->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => '', 'selection_price_type' => 0, 'price' => 20],
            ['product_id' => $simpleMonthlySubscriptionInitialFee->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => '', 'selection_price_type' => 0, 'price' => 20],
            ['product_id' => $simpleTrialMonthlySubscription->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => '', 'selection_price_type' => 0, 'price' => 20],
            ['product_id' => $simpleTrialMonthlySubscriptionInitialFee->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => '', 'selection_price_type' => 0, 'price' => 20],
            ['product_id' => $virtualMonthlySubscription->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => '', 'selection_price_type' => 0, 'price' => 20],
            ['product_id' => $virtualTrialMonthlySubscription->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => '', 'selection_price_type' => 0, 'price' => 20],
            ['product_id' => $virtualTrialMonthlySubscriptionInitialFee->getId(), 'selection_qty' => 1, 'selection_can_change_qty' => 1, 'delete' => '', 'selection_price_type' => 0, 'price' => 20],
        ],
    ]
);
setBundleProductItems($bundleProduct);

// ----------------------------------------------------------------------------

// Create the configurable product
$product = $productInterfaceFactory->create();
$product->setTypeId("configurable")
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Configurable Subscription')
    ->setSku('configurable-subscription')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED);

// Associated products
$attributeValues = [];
$options = $attribute->getOptions();
foreach ($options as $option)
{
    $attributeValues[] = [
        'label' => $option->getValue(),
        'attribute_id' => $attribute->getId(),
        'value_index' => $option->getValue(),
    ];
}

$configurableAttributesData = [
    [
        'attribute_id' => $attribute->getId(),
        'code' => $attribute->getAttributeCode(),
        'label' => $attribute->getStoreLabel(),
        'position' => '0',
        'values' => $attributeValues,
    ],
];

$associatedProductIds = [
    $simpleProduct->getId(),
    $simpleMonthlySubscription->getId(),
    $simpleQuarterlySubscription->getId()
];

$optionsFactory = $objectManager->create(\Magento\ConfigurableProduct\Helper\Product\Options\Factory::class);
$configurableOptions = $optionsFactory->create($configurableAttributesData);
$extensionConfigurableAttributes = $product->getExtensionAttributes();
$extensionConfigurableAttributes->setConfigurableProductOptions($configurableOptions);
$extensionConfigurableAttributes->setConfigurableProductLinks($associatedProductIds);
$product->setExtensionAttributes($extensionConfigurableAttributes);

$configurableProduct = $productRepository->save($product);


// ----------------------------------------------------------------------------

// Create the configurable product
$product = $productInterfaceFactory->create();
$product->setTypeId("configurable")
    ->setAttributeSetId($defaultAttributeSetId)
    ->setStoreId($defaultStoreId)
    ->setWebsiteIds($defaultWebsiteIds)
    ->setName('Configurable Virtual Subscription')
    ->setSku('configurable-virtual-subscription')
    ->setPrice(10)
    ->setTaxClassId(2)
    ->setStockData(['use_config_manage_stock' => 0])
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED);

// Associated products
$attributeValues = [];
$options = $attribute->getOptions();
foreach ($options as $option)
{
    $attributeValues[] = [
        'label' => $option->getValue(),
        'attribute_id' => $attribute->getId(),
        'value_index' => $option->getValue(),
    ];
}

$configurableAttributesData = [
    [
        'attribute_id' => $attribute->getId(),
        'code' => $attribute->getAttributeCode(),
        'label' => $attribute->getStoreLabel(),
        'position' => '0',
        'values' => $attributeValues,
    ],
];

$associatedProductIds = [
    $virtualProduct->getId(),
    $virtualMonthlySubscription->getId(),
    $virtualQuarterlySubscription->getId()
];

$optionsFactory = $objectManager->create(\Magento\ConfigurableProduct\Helper\Product\Options\Factory::class);
$configurableOptions = $optionsFactory->create($configurableAttributesData);
$extensionConfigurableAttributes = $product->getExtensionAttributes();
$extensionConfigurableAttributes->setConfigurableProductOptions($configurableOptions);
$extensionConfigurableAttributes->setConfigurableProductLinks($associatedProductIds);
$product->setExtensionAttributes($extensionConfigurableAttributes);

$configurableProduct = $productRepository->save($product);
