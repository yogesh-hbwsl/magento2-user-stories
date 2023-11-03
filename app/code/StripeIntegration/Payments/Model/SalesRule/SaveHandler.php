<?php
namespace StripeIntegration\Payments\Model\SalesRule;

use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\EntityManager\Operation\ExtensionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Model\Rule;
use StripeIntegration\Payments\Api\Data\CouponInterfaceFactory;
use StripeIntegration\Payments\Api\Data\CouponInterface as CouponInterface;
use StripeIntegration\Payments\Model\Coupon;
use StripeIntegration\Payments\Model\ResourceModel\Coupon as ResourceCoupon;
use StripeIntegration\Payments\Model\Config as StripeConfig;

/**
 * SaveHandler - to save the custom field value to the 'stripe_coupons' table
 */
class SaveHandler implements ExtensionInterface
{
    const COUPON_ONCE_MONTHS = 0;

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
     * @var StripeConfig
     */
    protected $stripeConfig;

    /**
     * @param MetadataPool $metadataPool
     * @param CouponInterfaceFactory $couponInterfaceFactory
     * @param ResourceCoupon $resourceCoupon
     * @param StripeConfig $stripeConfig
     */
    public function __construct(
        MetadataPool $metadataPool,
        CouponInterfaceFactory $couponInterfaceFactory,
        ResourceCoupon $resourceCoupon,
        StripeConfig $stripeConfig
    ) {
        $this->metadataPool = $metadataPool;
        $this->couponInterfaceFactory = $couponInterfaceFactory;
        $this->resourceCoupon = $resourceCoupon;
        $this->stripeConfig = $stripeConfig;
    }

    /**
     * Save stripe subscription coupon expires values
     *
     * @param Rule $entity Entity
     * @param array<mixed> $arguments Arguments
     * @return bool|object
     * @throws \Exception
     */
    public function execute($entity, $arguments = [])
    {
        $metadata = $this->metadataPool->getMetadata(RuleInterface::class);
        $linkFieldValue = $entity->getData($metadata->getIdentifierField());
        $attributes = $entity->getExtensionAttributes() ?: [];

        if (isset($attributes[CouponInterface::EXTENSION_CODE]) && $this->stripeConfig->isSubscriptionsEnabled()) {
            $inputData = $attributes[CouponInterface::EXTENSION_CODE];

            /** @var Coupon $couponModel */
            $couponModel = $this->couponInterfaceFactory->create();
            $this->resourceCoupon->load($couponModel, $linkFieldValue, CouponInterface::COUPON_RULE_ID);

            if ($inputData instanceof CouponInterface) {
                /** @var Coupon $inputData */
                $couponModel->addData($inputData->getData());
            } else {
                $couponModel->addData($inputData);
            }

            if ($couponModel->getCouponSalesRuleId() != $linkFieldValue) {
                $couponModel->setCouponId(null);
                $couponModel->setCouponSalesRuleId($linkFieldValue);
            }

            if ($couponModel->getCouponDuration() && ($couponModel->getCouponDuration() !== Coupon::COUPON_FOREVER)) {
                if ($couponModel->getCouponDuration() === Coupon::COUPON_ONCE) {
                    $couponModel->setCouponMonths(self::COUPON_ONCE_MONTHS);
                }
                if ($couponModel->getCouponDuration() === Coupon::COUPON_REPEATING) {
                    $this->validateRequiredFields($couponModel);
                }

                $this->resourceCoupon->save($couponModel);
            } elseif (!($couponModel->getCouponDuration())
                || ($couponModel->getCouponDuration() === Coupon::COUPON_FOREVER && $couponModel->getCouponId())) {
                $this->resourceCoupon->delete($couponModel);
            }

        }
        return $entity;
    }

    /**
     * Validate coupon expire month is numeric
     *
     * @param Coupon $couponModel
     * @throws LocalizedException
     */
    private function validateRequiredFields($couponModel)
    {
        if (!is_numeric($couponModel->getCouponMonths())) {
            throw new LocalizedException(__('The coupon duration is not a valid number.'));
        }
    }
}
