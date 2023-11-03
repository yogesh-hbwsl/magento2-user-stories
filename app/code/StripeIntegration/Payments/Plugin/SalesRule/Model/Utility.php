<?php

namespace StripeIntegration\Payments\Plugin\SalesRule\Model;

class Utility
{
    private $couponCollection;
    private $recurringOrderData;

    public function __construct(
        \StripeIntegration\Payments\Model\ResourceModel\Coupon\Collection $couponCollection,
        \StripeIntegration\Payments\Helper\RecurringOrderData $recurringOrderData
    )
    {
        $this->couponCollection = $couponCollection;
        $this->recurringOrderData = $recurringOrderData;
    }

    /**
     * Check if rule can be applied for specific address/quote/customer
     *
     * @param \Magento\SalesRule\Model\Rule $rule
     * @param \Magento\Quote\Model\Quote\Address $address
     * @return bool
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function afterCanProcessRule(\Magento\SalesRule\Model\Utility $subject, $result, $rule, $address)
    {
        if (is_object($address))
        {
            $coupon = $this->couponCollection->getByRuleId($rule->getId());
            if ($coupon && $coupon->expires() && $this->recurringOrderData->discountObject == "none")
            {
                // The coupon has expired, do not apply the rule
                return false;
            }
        }

        return $result;
    }
}
