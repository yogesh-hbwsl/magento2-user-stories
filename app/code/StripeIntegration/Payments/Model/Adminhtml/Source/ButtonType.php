<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

class ButtonType implements ArrayInterface
{
    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'default', 'label' => __('Default')],
            ['value' => 'buy', 'label' => __('Buy')],
            ['value' => 'donate', 'label' => __('Donate')]
        ];
    }
}
