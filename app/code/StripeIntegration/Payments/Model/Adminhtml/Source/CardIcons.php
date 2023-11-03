<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

class CardIcons
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 0,
                'label' => __('Display all card icons')
            ],
            [
                'value' => 1,
                'label' => __('Show only specific cards')
            ],
            [
                'value' => 2,
                'label' => __('Disabled')
            ],
        ];
    }
}
