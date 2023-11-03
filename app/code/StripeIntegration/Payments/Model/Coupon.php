<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Api\Data\CouponInterface;
use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception;

/**
 * Coupon - Database handling
 */
class Coupon extends \Magento\Framework\Model\AbstractModel implements CouponInterface
{
    /**
     * Constant for Coupon Type - Sales Rule
     */
    const COUPON_FOREVER = 'forever';
    const COUPON_ONCE = 'once';
    const COUPON_REPEATING = 'repeating';

    /**
     * Initialise resource model
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\Coupon::class);
    }

    /**
     * Set Duration type based on the input
     *
     * @return mixed|string|null
     */
    public function duration()
    {
        switch ($this->getCouponDuration()) {
            case 'once':
            case 'repeating':
                return $this->getCouponDuration();

            default:
                return 'forever';
        }
    }

    /**
     * Set the expired month based on the input
     *
     * @return mixed|string|null
     */
    public function months()
    {
        if ($this->duration() == 'repeating' && is_numeric($this->getCouponMonths()) && $this->getCouponMonths() > 0) {
            return $this->getCouponMonths();
        }

        return null;
    }

    public function expires()
    {
        return $this->duration() != "forever";
    }

    /**
     * Get coupon ID
     *
     * @return int|mixed|null
     */
    public function getCouponId()
    {
        return $this->_getData(self::COUPON_ID);
    }

    /**
     * Set Coupon ID
     *
     * @param int $couponId
     * @return $this|Coupon
     */
    public function setCouponId($couponId)
    {
        $this->setData(self::COUPON_ID, $couponId);
        return $this;
    }

    /**
     * Get Coupon Sales Rule ID
     *
     * @return int|mixed|null
     */
    public function getCouponSalesRuleId()
    {
        return $this->_getData(self::COUPON_RULE_ID);
    }

    /**
     * Set Sales Rule ID
     *
     * @param int $ruleId
     * @return $this|Coupon
     */
    public function setCouponSalesRuleId($ruleId)
    {
        $this->setData(self::COUPON_RULE_ID, $ruleId);
        return $this;
    }

    /**
     * Get Coupon Duration
     *
     * @return mixed|string|null
     */
    public function getCouponDuration()
    {
        return $this->_getData(self::COUPON_DURATION);
    }

    /**
     * Set Coupon Duration
     *
     * @param string $couponDuration
     * @return $this|Coupon
     */
    public function setCouponDuration($couponDuration)
    {
        $this->setData(self::COUPON_DURATION, $couponDuration);
        return $this;
    }

    /**
     * Get Coupon Months
     *
     * @return mixed|string|null
     */
    public function getCouponMonths()
    {
        return $this->_getData(self::COUPON_MONTHS);
    }

    /**
     * Set Coupon Months
     *
     * @param string $couponMonths
     * @return $this|Coupon
     */
    public function setCouponMonths($couponMonths)
    {
        $this->setData(self::COUPON_MONTHS, $couponMonths);
        return $this;
    }
}
