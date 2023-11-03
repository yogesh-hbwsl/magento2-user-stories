<?php

namespace StripeIntegration\Payments\Model\Stripe;

use Magento\Framework\Exception\LocalizedException;

class Coupon extends StripeObject
{
    protected $objectSpace = 'coupons';
    public $rule = null;

    public function fromSubscriptionProfile($profile)
    {
        $currency = $profile['currency'];
        $amount = $profile['discount_amount_magento'];
        $data = $this->getCouponParams($amount, $currency, $profile['expiring_coupon']['rule_id'], true);

        if (!$data)
            return $this;

        $this->getObject($data['id']);

        if (!$this->object)
            $this->createObject($data);

        if (!$this->object)
            throw new \Magento\Framework\Exception\LocalizedException(__("The discount could not be created in Stripe: %2", $this->lastError));

        return $this;
    }
    public function fromGiftCards($order)
    {
        $currency = $order->getOrderCurrencyCode();
        $amount = $order->getGiftCardsAmount();

        $discountType = "amount_off";
        $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);

        $giftCards = json_decode($order->getGiftCards());
        if (count($giftCards) > 1)
        {
            $name = __("%1 Gift Cards", $this->helper->addCurrencySymbol($amount, $currency));
        }
        else
        {
            $name = __("%1 Gift Card", $this->helper->addCurrencySymbol($amount, $currency));
        }

        $params = [
            $discountType => $stripeAmount,
            'currency' => $currency,
            'name' => $name
        ];

        $this->createObject($params);

        if (!$this->object)
            throw new \Magento\Framework\Exception\LocalizedException(__("The gift cards for order #%1 could not be created in Stripe: %2", $order->getIncrementId(), $this->lastError));

        return $this;
    }

    public function getCouponExpirationParams($ruleId)
    {
        $defaults = ['duration' => 'forever'];

        if (empty($ruleId))
            return $defaults;

        $coupon = $this->helper->loadStripeCouponByRuleId($ruleId);
        $duration = $coupon->duration();
        $months = $coupon->months();

        if ($months && $months > 0)
        {
            return [
                'duration' => $duration,
                'duration_in_months' => $months
            ];
        }

        return ['duration' => $duration];
    }

    protected function getCouponParams($amount, $currency, $ruleId, $hasSubscriptions)
    {
        if (empty($amount) || empty($ruleId))
            return null;

        $this->rule = $rule = $this->helper->loadRuleByRuleId($ruleId);
        $action = $rule->getSimpleAction();
        if (empty($action))
            return null;

        if (!$hasSubscriptions)
            $action = "by_fixed";

        $discountType = "amount_off";
        $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);
        $couponId = ((string)$stripeAmount) . strtoupper($currency);
        $name = $this->helper->addCurrencySymbol($amount, $currency) . " Discount";

        $expirationParams = $this->getCouponExpirationParams($ruleId);

        switch ($expirationParams['duration'])
        {
            case 'repeating':
                $couponId .= "-months-" . $expirationParams['duration_in_months'];
                break;
            case 'once':
                $couponId .= "-once";
                break;
        }

        $params = [
            'id' => $couponId,
            $discountType => $stripeAmount,
            'currency' => $currency,
            'name' => $name
        ];

        $params = array_merge($params, $expirationParams);

        return $params;
    }

    public function getApplyToShipping()
    {
        if (!empty($this->rule))
        {
            return $this->rule->getApplyToShipping();
        }

        return false;
    }
}
