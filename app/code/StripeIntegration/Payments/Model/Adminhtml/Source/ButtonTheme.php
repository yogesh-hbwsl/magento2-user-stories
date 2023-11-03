<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

class ButtonTheme implements ArrayInterface
{
    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'dark', 'label' => __('Dark')],
            ['value' => 'light', 'label' => __('Light')],
            ['value' => 'light-outline', 'label' => __('Light-Outline')]
        ];
    }
}
