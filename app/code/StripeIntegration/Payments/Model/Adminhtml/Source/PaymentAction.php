<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

use Magento\Payment\Model\Method\AbstractMethod;

class PaymentAction implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            [
                'value' => AbstractMethod::ACTION_ORDER,
                'label' => __('Order')
            ],
            [
                'value' => AbstractMethod::ACTION_AUTHORIZE,
                'label' => __('Authorize Only')
            ],
            [
                'value' => AbstractMethod::ACTION_AUTHORIZE_CAPTURE,
                'label' => __('Authorize and Capture')
            ],
        ];
    }
}
