<?php

namespace StripeIntegration\Payments\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class Patch002ConfigurableSubscriptionAttributes
    implements DataPatchInterface,
    PatchRevertableInterface
{
    const GROUP_NAME = 'Subscriptions by Stripe';

    private $attributes = [
        'stripe_sub_ud'          => [
            'type'                  => 'int',
            'label'                 => 'Upgrades and downgrades',
            'note'                  => 'Allow customers to upgrade or downgrade active subscriptions from the customer account section.',
            'input'                 => 'select',
            'source'                => 'StripeIntegration\Payments\Model\Adminhtml\Source\EnabledLocally',
            'sort_order'            => 150,
            'global'                => false,
            'group'                 => self::GROUP_NAME,
            'is_used_in_grid'       => false,
            'is_visible_in_grid'    => false,
            'is_filterable_in_grid' => false,
            'used_for_promo_rules'  => true,
            'required'              => false,
            'apply_to'              => 'configurable,simple,virtual',
        ],
        'stripe_sub_prorate_u'   => [
            'type'                  => 'int',
            'label'                 => 'Prorations for upgrades',
            'note'                  => 'Upgrades will incur an extra fee for the price difference.',
            'input'                 => 'select',
            'source'                => 'StripeIntegration\Payments\Model\Adminhtml\Source\EnabledLocally',
            'sort_order'            => 160,
            'global'                => false,
            'group'                 => self::GROUP_NAME,
            'is_used_in_grid'       => false,
            'is_visible_in_grid'    => false,
            'is_filterable_in_grid' => false,
            'used_for_promo_rules'  => true,
            'required'              => false,
            'apply_to'              => 'configurable',
        ],
        'stripe_sub_prorate_d'   => [
            'type'                  => 'int',
            'label'                 => 'Prorations for downgrades',
            'note'                  => 'Downgrades will refund the the price difference to the customer.',
            'input'                 => 'select',
            'source'                => 'StripeIntegration\Payments\Model\Adminhtml\Source\EnabledLocally',
            'sort_order'            => 170,
            'global'                => false,
            'group'                 => self::GROUP_NAME,
            'is_used_in_grid'       => false,
            'is_visible_in_grid'    => false,
            'is_filterable_in_grid' => false,
            'used_for_promo_rules'  => true,
            'required'              => false,
            'apply_to'              => 'configurable',
        ]
    ];

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
        $categorySetup = $this->_categorySetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach ($this->attributes as $code => $params)
            $categorySetup->addAttribute(\Magento\Catalog\Model\Product::ENTITY, $code, $params);

        $this->sortGroup(self::GROUP_NAME, 18);

        return $this;
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
            \StripeIntegration\Payments\Setup\Patch\Data\InitialInstall::class
        ];
    }

    public function revert()
    {

        $setup = $this->moduleDataSetup;
        $setup->getConnection()->startSetup();

        $eavSetup = $this->_eavSetupFactory->create();
        $entityTypeId = 4; // \Magento\Catalog\Model\Product::ENTITY

        foreach ($this->attributes as $code => $params)
        {
            $eavSetup->removeAttribute($entityTypeId, $code);
        }

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
}
