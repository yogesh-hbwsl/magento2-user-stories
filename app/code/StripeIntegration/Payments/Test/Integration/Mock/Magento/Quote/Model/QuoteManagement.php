<?php

namespace StripeIntegration\Payments\Test\Integration\Mock\Magento\Quote\Model;

use Magento\Quote\Model\Quote as QuoteEntity;

class QuoteManagement extends \Magento\Quote\Model\QuoteManagement
{
    public static $crashAfterOrderSave = false;

    public function submit(QuoteEntity $quote, $orderData = [])
    {
        if (self::$crashAfterOrderSave)
        {
            $order = parent::submit($quote, $orderData);
            throw new \Exception("crashAfterOrderSave");
        }
        return parent::submit($quote, $orderData);
    }
}
