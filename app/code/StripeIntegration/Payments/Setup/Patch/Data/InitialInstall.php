<?php

namespace StripeIntegration\Payments\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class InitialInstall
    implements DataPatchInterface,
    PatchRevertableInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;
    private $_attributeFactory;
    private $_attributeGroupFactory;
    private $_attributeManagement;
    private $_attributeSetFactory;
    private $_categorySetupFactory;
    private $_eavSetupFactory;
    private $_eavTypeFactory;
    private $_groupCollectionFactory;
    private $migrate;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        \Magento\Catalog\Setup\CategorySetupFactory $categorySetupFactory,
        \Magento\Eav\Model\Entity\TypeFactory $eavTypeFactory,
        \Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory $attributeFactory,
        \Magento\Eav\Model\Entity\Attribute\SetFactory $attributeSetFactory,
        \Magento\Eav\Model\Entity\Attribute\GroupFactory $attributeGroupFactory,
        \Magento\Eav\Model\AttributeManagement $attributeManagement,
        \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory,
        \Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\CollectionFactory $groupCollectionFactory
    ) {
        /**
         * If before, we pass $setup as argument in install/upgrade function, from now we start
         * inject it with DI. If you want to use setup, you can inject it, with the same way as here
         */
        $this->moduleDataSetup = $moduleDataSetup;
        $this->_categorySetupFactory = $categorySetupFactory;
        $this->_eavTypeFactory = $eavTypeFactory;
        $this->_attributeFactory = $attributeFactory;
        $this->_attributeSetFactory = $attributeSetFactory;
        $this->_attributeGroupFactory = $attributeGroupFactory;
        $this->_attributeManagement = $attributeManagement;
        $this->_eavSetupFactory = $eavSetupFactory;
        $this->_groupCollectionFactory = $groupCollectionFactory;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->migrate = $objectManager->create(\StripeIntegration\Payments\Helper\Migrate::class);
        $setup = $this->moduleDataSetup;

        $this->initSubscriptions($setup);
        $this->migrate->orders();
        $this->migrate->customers($setup);
        $this->migrate->subscriptions($setup);
        $this->updateSubscriptionAttributes($setup);

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        /**
         * This is dependency to another patch. Dependency should be applied first
         * One patch can have few dependencies
         * Patches do not have versions, so if in old approach with Install/Ugrade data scripts you used
         * versions, right now you need to point from patch with higher version to patch with lower version
         * But please, note, that some of your patches can be independent and can be installed in any sequence
         * So use dependencies only if this important for you
         */
        return [
            // SomeDependency::class
        ];
    }

    public function revert()
    {
        $setup = $this->moduleDataSetup;
        $setup->getConnection()->startSetup();

        $defaultConnection = $setup->getConnection();

        $defaultConnection->delete(
            $this->moduleDataSetup->getTable('core_config_data'),
            "path LIKE 'payment/stripe_payments%'"
        );

        $eavSetup = $this->_eavSetupFactory->create();
        $entityTypeId = 4; // \Magento\Catalog\Model\Product::ENTITY
        $eavSetup->removeAttribute($entityTypeId, 'stripe_sub_enabled');
        $eavSetup->removeAttribute($entityTypeId, 'stripe_sub_interval');
        $eavSetup->removeAttribute($entityTypeId, 'stripe_sub_interval_count');
        $eavSetup->removeAttribute($entityTypeId, 'stripe_sub_trial');
        $eavSetup->removeAttribute($entityTypeId, 'stripe_sub_initial_fee');
        $eavSetup->removeAttribute($entityTypeId, 'stripe_sub_enabled');

        $setup->getConnection()->endSetup();
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        /**
         * This internal Magento method, that means that some patches with time can change their names,
         * but changing name should not affect installation process, that's why if we will change name of the patch
         * we will add alias here
         */
        return [];
    }


    private function initSubscriptions($setup)
    {
        $groupName = 'Subscriptions by Stripe';

        $attributes = [
            'stripe_sub_enabled' => [
                'type'                  => 'int',
                'label'                 => 'Subscription Enabled',
                'input'                 => 'boolean',
                'source'                => 'Magento\Eav\Model\Entity\Attribute\Source\Boolean',
                'sort_order'            => 100,
                'global'                => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
                'group'                 => $groupName,
                'is_used_in_grid'       => false,
                'is_visible_in_grid'    => false,
                'is_filterable_in_grid' => false,
                'used_for_promo_rules'  => true,
                'required'              => false
            ],
            'stripe_sub_interval' => [
                'type'                  => 'varchar',
                'label'                 => 'Frequency',
                'input'                 => 'select',
                'source'                => 'StripeIntegration\Payments\Model\Adminhtml\Source\BillingInterval',
                'sort_order'            => 110,
                'global'                => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
                'group'                 => $groupName,
                'is_used_in_grid'       => false,
                'is_visible_in_grid'    => false,
                'is_filterable_in_grid' => false,
                'used_for_promo_rules'  => true,
                'required'              => false
            ],
            'stripe_sub_interval_count' => [
                'type'                  => 'varchar',
                'label'                 => 'Repeat Every',
                'input'                 => 'text',
                'sort_order'            => 120,
                'global'                => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
                'group'                 => $groupName,
                'is_used_in_grid'       => false,
                'is_visible_in_grid'    => false,
                'is_filterable_in_grid' => false,
                'used_for_promo_rules'  => true,
                'required'              => false
            ],
            'stripe_sub_trial'       => [
                'type'                  => 'int',
                'label'                 => 'Trial Days',
                'input'                 => 'text',
                'sort_order'            => 130,
                'global'                => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
                'group'                 => $groupName,
                'is_used_in_grid'       => false,
                'is_visible_in_grid'    => false,
                'is_filterable_in_grid' => false,
                'used_for_promo_rules'  => true,
                'required'              => false
            ],
            'stripe_sub_initial_fee' => [
                'type'                  => 'decimal',
                'label'                 => 'Initial Fee',
                'input'                 => 'text',
                'sort_order'            => 140,
                'global'                => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
                'group'                 => $groupName,
                'is_used_in_grid'       => false,
                'is_visible_in_grid'    => false,
                'is_filterable_in_grid' => false,
                'used_for_promo_rules'  => true,
                'required'              => false
            ]
        ];

        $categorySetup = $this->_categorySetupFactory->create(['setup' => $setup]);

        foreach ($attributes as $code => $params)
            $categorySetup->addAttribute(\Magento\Catalog\Model\Product::ENTITY, $code, $params);

        $this->sortGroup($groupName, 11);
    }

    private function sortGroup($attributeGroupName, $order)
    {
        $entityType = $this->_eavTypeFactory->create()->loadByCode('catalog_product');
        $setCollection = $this->_attributeSetFactory->create()->getCollection();
        $setCollection->addFieldToFilter('entity_type_id', $entityType->getId());

        foreach ($setCollection as $attributeSet)
        {
            $group = $this->_groupCollectionFactory->create()
                ->addFieldToFilter('attribute_set_id', $attributeSet->getId())
                ->addFieldToFilter('attribute_group_name', $attributeGroupName)
                ->getFirstItem()
                ->setSortOrder($order)
                ->save();
        }

        return true;
    }

    public function updateSubscriptionAttributes($setup)
    {
        $eavSetup = $this->_eavSetupFactory->create();
        $eavSetup->updateAttribute('catalog_product', 'stripe_sub_enabled', 'apply_to', 'simple,virtual');
        $eavSetup->updateAttribute('catalog_product', 'stripe_sub_interval', 'apply_to', 'simple,virtual');
        $eavSetup->updateAttribute('catalog_product', 'stripe_sub_interval_count', 'apply_to', 'simple,virtual');
        $eavSetup->updateAttribute('catalog_product', 'stripe_sub_trial', 'apply_to', 'simple,virtual');
        $eavSetup->updateAttribute('catalog_product', 'stripe_sub_initial_fee', 'apply_to', 'simple,virtual');
    }
}
