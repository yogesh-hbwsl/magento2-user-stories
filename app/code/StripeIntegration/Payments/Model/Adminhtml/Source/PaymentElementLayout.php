<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

use Magento\Payment\Model\Method\AbstractMethod;

class PaymentElementLayout implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 0,
                'label' => __('Horizontal - Tabs')
            ],
            [
                'value' => 1,
                'label' => __('Vertical - Accordion')
            ],
        ];
    }
}
