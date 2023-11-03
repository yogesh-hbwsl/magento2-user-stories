<?php

namespace StripeIntegration\Payments\Model\Stripe;

class Price extends StripeObject
{
    protected $objectSpace = 'prices';

    public function generateNickname($stripeAmount, $currency, $interval, $intervalCount)
    {
        if (!empty($interval) && !empty($intervalCount))
        {
            return $this->subscriptionsHelper->formatInterval($stripeAmount, $currency, $intervalCount, $interval);
        }

        return $this->helper->formatStripePrice($stripeAmount, $currency);
    }

    public function generateId($stripeProductId, $stripeUnitAmount, $currency, $interval, $intervalCount)
    {
        $id = "{$stripeUnitAmount}{$currency}";

        if ($interval && $intervalCount)
        {
            $id .= "-{$interval}-{$intervalCount}";
        }

        $id .= "-{$stripeProductId}";

        return $id;
    }

    protected function formatCreationData($stripeProductId, $stripeUnitAmount, $currency, $interval, $intervalCount)
    {
        $data = [
            'currency' => strtoupper($currency),
            'unit_amount' => $stripeUnitAmount,
            'product' => $stripeProductId
        ];

        if (!empty($interval) && !empty($intervalCount))
        {
            $data['recurring'] = [
                'interval' => $interval,
                'interval_count' => $intervalCount
            ];
        }

        $data['nickname'] = $this->generateNickname($stripeUnitAmount, $currency, $interval, $intervalCount);

        return $data;
    }

    public function fromData($stripeProductId, $stripeUnitAmount, $currency, $interval = null, $intervalCount = null)
    {
        $data = $this->formatCreationData($stripeProductId, $stripeUnitAmount, $currency, $interval, $intervalCount);
        $priceId = $this->generateId($stripeProductId, $stripeUnitAmount, $currency, $interval, $intervalCount);

        if (!$this->lookupSingle($priceId))
        {
            $data['lookup_key'] = $priceId;
            $this->createObject($data);
        }

        if (!$this->object)
            throw new \Magento\Framework\Exception\LocalizedException(__("The price could not be created in Stripe: %1", $this->lastError));

        return $this;
    }

    public function fromOrderItem($item, $order, $stripeProduct)
    {
        $stripeProductId = $stripeProduct->id;
        $stripeUnitAmount = $this->helper->convertMagentoAmountToStripeAmount($item->getPrice(), $order->getOrderCurrencyCode());
        $currency = strtoupper($order->getOrderCurrencyCode());
        $interval = null;
        $intervalCount = null;

        $data = $this->formatCreationData($stripeProductId, $stripeUnitAmount, $currency, $interval, $intervalCount);
        $priceId = $this->generateId($stripeProductId, $stripeUnitAmount, $currency, $interval, $intervalCount);

        if (!$this->lookupSingle($priceId))
        {
            $data['lookup_key'] = $priceId;
            $this->createObject($data);
        }

        if (!$this->object)
            throw new \Magento\Framework\Exception\LocalizedException(__("The price for product \"%1\" could not be created in Stripe: %2", $item->getName(), $this->lastError));

        return $this;
    }
}
