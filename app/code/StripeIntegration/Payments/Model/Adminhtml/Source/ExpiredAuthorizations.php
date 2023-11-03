<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

class ExpiredAuthorizations
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 0,
                'label' => __('Warn admin and don\'t capture')
            ],
            [
                'value' => 1,
                'label' => __('Try to re-create the charge using the same card')
            ],
        ];
    }
}
