<?php

namespace StripeIntegration\Payments\Test\Integration\Mock\Magento\Sales\Model;

class Order extends \Magento\Sales\Model\Order
{
    public static $crashBeforeOrderSave = false;

    public function place()
    {
        $result = parent::place();

        if (self::$crashBeforeOrderSave)
        {
            throw new \Exception("crashBeforeOrderSave");
        }

        return $result;
    }

}
