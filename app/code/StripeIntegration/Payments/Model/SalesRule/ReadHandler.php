<?php
namespace StripeIntegration\Payments\Model\SalesRule;

use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\EntityManager\Operation\ExtensionInterface;
use StripeIntegration\Payments\Api\Data\CouponInterface as CouponInterface;
use StripeIntegration\Payments\Api\Data\CouponInterfaceFactory;
use StripeIntegration\Payments\Model\ResourceModel\Coupon as ResourceCoupon;
use StripeIntegration\Payments\Model\Coupon;
use Magento\SalesRule\Api\Data\RuleInterface as MagentoRuleInterface;
use Magento\SalesRule\Api\Data\RuleExtensionInterface;

/**
 * ReadHandler - Fetch the custom field value from 'stripe_coupons' table
 */
class ReadHandler implements ExtensionInterface
{
    /**
     * @var MetadataPool
     */
    protected $metadataPool;

    /**
     * @var CouponInterfaceFactory
     */
    protected $couponInterfaceFactory;

    /**
     * @var ResourceCoupon
     */
    protected $resourceCoupon;

    /**
     * @param MetadataPool $metadataPool
     * @param CouponInterfaceFactory $couponInterfaceFactory
     * @param ResourceCoupon $resourceCoupon
     */
    public function __construct(
        MetadataPool $metadataPool,
        CouponInterfaceFactory $couponInterfaceFactory,
        ResourceCoupon $resourceCoupon
    ) {
        $this->metadataPool = $metadataPool;
        $this->couponInterfaceFactory = $couponInterfaceFactory;
        $this->resourceCoupon = $resourceCoupon;
    }

    /**
     * Read the custom field values from custom table
     *
     * @param object $entity
     * @param array<mixed> $arguments
     * @return bool|object
     * @throws \Exception
     */
    public function execute($entity, $arguments = [])
    {
        $linkField = $this->metadataPool->getMetadata(MagentoRuleInterface::class)->getIdentifierField();
        $ruleLinkId = $entity->getDataByKey($linkField);

        if ($ruleLinkId) {
            /** @var RuleExtensionInterface|array<mixed> $attributes */
            $attributes = $entity->getExtensionAttributes() ?: [];

            /** @var Coupon $couponModel */
            $couponModel = $this->couponInterfaceFactory->create();
            $this->resourceCoupon->load($couponModel, $ruleLinkId, CouponInterface::COUPON_RULE_ID);
            $attributes[CouponInterface::EXTENSION_CODE] = $couponModel;
            $entity->setData(CouponInterface::RULE_NAME, $couponModel);
            $entity->setExtensionAttributes($attributes);
        }

        return $entity;
    }
}
