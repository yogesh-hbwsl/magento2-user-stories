<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source\Subscriptions;

use Magento\Framework\Data\ValueSourceInterface;

class ProrationsConfiguration implements ValueSourceInterface
{
    public function __construct(
    )
    {
    }

    public function getValue($name)
    {
        return 1;
    }
}
