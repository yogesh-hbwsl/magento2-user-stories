<?php

namespace StripeIntegration\Payments\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class Patch003InitialFees
    implements DataPatchInterface,
    PatchRevertableInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;
    private $areaCode;
    private $orderItemCollectionFactory;
    private $orderItemFactory;
    private $productCollectionFactory;
    private $subscriptionsFactory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory $orderItemCollectionFactory,
        \Magento\Sales\Model\Order\ItemFactory $orderItemFactory,
        \StripeIntegration\Payments\Helper\SubscriptionsFactory $subscriptionsFactory,
        \StripeIntegration\Payments\Helper\AreaCode $areaCode
    ) {
        /**
         * If before, we pass $setup as argument in install/upgrade function, from now we start
         * inject it with DI. If you want to use setup, you can inject it, with the same way as here
         */
        $this->moduleDataSetup = $moduleDataSetup;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->orderItemCollectionFactory = $orderItemCollectionFactory;
        $this->orderItemFactory = $orderItemFactory;
        $this->subscriptionsFactory = $subscriptionsFactory;
        $this->areaCode = $areaCode;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $this->areaCode->setAreaCode();
        $subscriptionsHelper = $this->subscriptionsFactory->create();

        // Get a list of all subscription products which have an initial fee
        $collection = $this->productCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->addAttributeToFilter('stripe_sub_enabled', 1)
            ->addAttributeToFilter('stripe_sub_initial_fee', ['gt' => 0]);

        // Get all order items for those products
        $orderItems = $this->orderItemCollectionFactory->create()
            ->addFieldToFilter('product_id', ['in' => $collection->getAllIds()]);

        // For each order item, set a static value to its initial fee amounts
        foreach ($orderItems as $orderItem)
        {
            $product = $orderItem->getProduct();
            $order = $orderItem->getOrder();

            if ($orderItem->getParentItemId())
            {
                $parentItem = $this->orderItemFactory->create()->load($orderItem->getParentItemId());
                $orderItem->setParentItem($parentItem);
            }

            $initialFeeDetails = $subscriptionsHelper->getInitialFeeDetails($product, $order, $orderItem);
            $item = $subscriptionsHelper->getVisibleSubscriptionItem($orderItem);
            $item->setInitialFee($initialFeeDetails['initial_fee']);
            $item->setBaseInitialFee($initialFeeDetails['base_initial_fee']);
            $item->setInitialFeeTax($initialFeeDetails['tax']);
            $item->setBaseInitialFeeTax($initialFeeDetails['base_tax']);
            $item->save();
        }

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
            \StripeIntegration\Payments\Setup\Patch\Data\InitialInstall::class
        ];
    }

    public function revert()
    {

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
