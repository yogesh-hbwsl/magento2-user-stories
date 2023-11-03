<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source\Subscriptions;

use Magento\Framework\Data\ValueSourceInterface;

class UpgradeDowngradeConfiguration implements ValueSourceInterface
{
    public function __construct(
    )
    {
    }

    public function getValue($name)
    {
        return 0;
    }
}
