<?php

namespace StripeIntegration\Payments\Setup\Patch\Data;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use StripeIntegration\Payments\Model\SubscriptionOptionsFactory;
use Magento\Eav\Setup\EavSetupFactory;

class Patch005ExtensionAttributes
    implements DataPatchInterface,
    PatchRevertableInterface
{
    private $moduleDataSetup;
    private $areaCode;
    private $scopeConfig;
    private $subscriptionOptionsFactory;
    private $eavSetupFactory;
    protected $productCollectionFactory;

    private $attributeCodes = [
        'stripe_sub_enabled',
        'stripe_sub_interval',
        'stripe_sub_interval_count',
        'stripe_sub_trial',
        'stripe_sub_initial_fee',
        'stripe_sub_ud',
        'stripe_sub_prorate_u',
        'stripe_sub_prorate_d'
    ];

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        \StripeIntegration\Payments\Helper\AreaCode $areaCode,
        ScopeConfigInterface $scopeConfig,
        SubscriptionOptionsFactory $subscriptionOptionsFactory,
        EavSetupFactory $eavSetupFactory,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory = null
    ) {
        $this->areaCode = $areaCode;
        $this->moduleDataSetup = $moduleDataSetup;
        $this->scopeConfig = $scopeConfig;
        $this->subscriptionOptionsFactory = $subscriptionOptionsFactory;
        $this->eavSetupFactory = $eavSetupFactory;
        $this->productCollectionFactory = $productCollectionFactory ?: ObjectManager::getInstance()
            ->get(CollectionFactory::class);
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $this->areaCode->setAreaCode();

        try
        {
            if ($this->scopeConfig->getValue("payment/stripe_payments_subscriptions/active")) {
                $products = $this->productCollectionFactory->create()
                    ->addFieldToSelect('*')
                    ->addFieldToFilter('stripe_sub_enabled', 1);

                foreach ($products as $item) {
                    $subscriptionOptionsModel = $this->subscriptionOptionsFactory->create();
                    $subscriptionOptionsModel->setProductId($item->getId());
                    $subscriptionOptionsModel->setSubEnabled($item->getStripeSubEnabled());
                    $subscriptionOptionsModel->setSubInterval($item->getStripeSubInterval());
                    $subscriptionOptionsModel->setSubIntervalCount($item->getStripeSubIntervalCount());
                    $subscriptionOptionsModel->setSubTrial($item->getStripeSubTrial());
                    $subscriptionOptionsModel->setSubInitialFee($item->getStripeSubInitialFee());
                    $subscriptionOptionsModel->save();
                }
            }

            $valueMap = [0 => 0, 1 => 0, 2 => 1];
            //Migrate Prorations fields
            $products = $this->productCollectionFactory->create()
                ->addFieldToSelect('*')
                ->addFieldToFilter('stripe_sub_ud', 2);

            foreach ($products as $item) {
                $subscriptionOptionsModel = $this->subscriptionOptionsFactory->create();
                $subscriptionOptionsModel->setProductId($item->getId());
                $subscriptionOptionsModel->setUpgradesDowngrades($valueMap[(int)$item->getStripeSubUd()]);
                $subscriptionOptionsModel->setUpgradesDowngradesUseConfig(0);
                $subscriptionOptionsModel->setProrateUpgrades($valueMap[(int)$item->getStripeSubProrateU()]);
                $subscriptionOptionsModel->setProrateUpgradesUseConfig(0);
                $subscriptionOptionsModel->setProrateDowngrades($valueMap[(int)$item->getStripeSubProrateD()]);
                $subscriptionOptionsModel->setProrateDowngradesUseConfig(0);
                $subscriptionOptionsModel->save();
            }


            /** @var \Magento\Eav\Setup\EavSetup $eavSetup */
            $eavSetup = $this->eavSetupFactory->create([
                'setup' => $this->moduleDataSetup
            ]);

            foreach ($this->attributeCodes as $attributeCode) {
                $eavSetup->removeAttribute(\Magento\Catalog\Model\Product::ENTITY, $attributeCode);
            }

        }
        catch (\Exception $e)
        {
            // Cases where the attribute has been deleted between module upgrades and downgrades
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }

    public function revert()
    {

    }
}
