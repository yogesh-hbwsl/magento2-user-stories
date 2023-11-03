<?php
namespace StripeIntegration\Payments\Model\Stripe\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * CouponDurationOptions - return coupon duration options
 */
class CouponDurationOptions implements OptionSourceInterface
{
    /**
     * Coupon Duration options
     *
     * @return array<mixed>|string[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'forever', 'label' => 'Forever'],
            ['value' => 'once', 'label' => 'Once'],
            ['value' => 'repeating', 'label' => 'Multiple months']
        ];
    }
}
