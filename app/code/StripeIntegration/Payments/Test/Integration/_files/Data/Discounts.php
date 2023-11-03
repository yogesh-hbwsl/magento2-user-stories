<?php

use Magento\Framework\ObjectManagerInterface;
use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Api\Data\CouponInterface;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

// $10 discount

$rule = $objectManager->create(RuleInterface::class);
$rule->setName('$10 discount')
    ->setIsAdvanced(true)
    ->setStopRulesProcessing(false)
    ->setDiscountQty(10)
    ->setCustomerGroupIds([0])
    ->setWebsiteIds([1])
    ->setCouponType(RuleInterface::COUPON_TYPE_SPECIFIC_COUPON)
    ->setSimpleAction(RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT_FOR_CART)
    ->setDiscountAmount(10)
    ->setIsActive(true);

$ruleRepository = $objectManager->get(RuleRepositoryInterface::class);
$rule = $ruleRepository->save($rule);

$coupon = $objectManager->create(CouponInterface::class);
$coupon->setCode('10_discount')
    ->setRuleId($rule->getRuleId());

$couponRepository = $objectManager->get(CouponRepositoryInterface::class);
$coupon = $couponRepository->save($coupon);

// 10% off

$rule = $objectManager->create(RuleInterface::class);
$rule->setName('10% discount')
    ->setIsAdvanced(true)
    ->setStopRulesProcessing(false)
    ->setDiscountQty(10)
    ->setCustomerGroupIds([0])
    ->setWebsiteIds([1])
    ->setCouponType(RuleInterface::COUPON_TYPE_SPECIFIC_COUPON)
    ->setSimpleAction(RuleInterface::DISCOUNT_ACTION_BY_PERCENT)
    ->setDiscountAmount(10)
    ->setIsActive(true);

$ruleRepository = $objectManager->get(RuleRepositoryInterface::class);
$rule = $ruleRepository->save($rule);

$coupon = $objectManager->create(CouponInterface::class);
$coupon->setCode('10_percent')
    ->setRuleId($rule->getRuleId());

$couponRepository = $objectManager->get(CouponRepositoryInterface::class);
$coupon = $couponRepository->save($coupon);

// 10% apply once

/** @var RuleInterface $rule */
$rule = $objectManager->create(RuleInterface::class);
$rule->setName('10% apply once discount')
    ->setIsAdvanced(true)
    ->setStopRulesProcessing(false)
    ->setDiscountQty(10)
    ->setCustomerGroupIds([0])
    ->setWebsiteIds([1])
    ->setCouponType(RuleInterface::COUPON_TYPE_SPECIFIC_COUPON)
    ->setSimpleAction(RuleInterface::DISCOUNT_ACTION_BY_PERCENT)
    ->setDiscountAmount(10)
    ->setIsActive(true);

$ruleRepository = $objectManager->get(RuleRepositoryInterface::class);
$rule = $ruleRepository->save($rule);

$coupon = $objectManager->create(CouponInterface::class);
$coupon->setCode('10_percent_apply_once')
    ->setUsagePerCustomer(1)
    ->setRuleId($rule->getRuleId());

$couponRepository = $objectManager->get(CouponRepositoryInterface::class);
$coupon = $couponRepository->save($coupon);

//Save custom data to stripe table.
$stripeCoupon = $objectManager->create(\StripeIntegration\Payments\Model\Coupon::class);
$stripeCoupon->setCouponDuration('once');
$stripeCoupon->setCouponMonths(0);
$stripeCoupon->setRuleId($rule->getRuleId());
$stripeCoupon->save();

// 10% apply for 3 months

/** @var RuleInterface $rule */
$rule = $objectManager->create(RuleInterface::class);
$rule->setName('10% discount for 3 months')
    ->setIsAdvanced(true)
    ->setStopRulesProcessing(false)
    ->setDiscountQty(10)
    ->setCustomerGroupIds([0])
    ->setWebsiteIds([1])
    ->setCouponType(RuleInterface::COUPON_TYPE_SPECIFIC_COUPON)
    ->setSimpleAction(RuleInterface::DISCOUNT_ACTION_BY_PERCENT)
    ->setDiscountAmount(10)
    ->setIsActive(true);

$ruleRepository = $objectManager->get(RuleRepositoryInterface::class);
$rule = $ruleRepository->save($rule);

$coupon = $objectManager->create(CouponInterface::class);
$coupon->setCode('10_percent_for_3months')
    ->setUsagePerCustomer(1)
    ->setRuleId($rule->getRuleId());

$couponRepository = $objectManager->get(CouponRepositoryInterface::class);
$coupon = $couponRepository->save($coupon);

//Save custom data to stripe table.
$stripeCoupon = $objectManager->create(\StripeIntegration\Payments\Model\Coupon::class);
$stripeCoupon->setCouponDuration('months');
$stripeCoupon->setCouponMonths(3);
$stripeCoupon->setRuleId($rule->getRuleId());
$stripeCoupon->save();
