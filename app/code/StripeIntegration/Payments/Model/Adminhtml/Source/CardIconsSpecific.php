<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

class CardIconsSpecific
{
    public function toOptionArray()
    {
        return [
            [
                'value' => "amex",
                'label' => __('American Express')
            ],
            [
                'value' => "discover",
                'label' => __('Discover')
            ],
            [
                'value' => "diners",
                'label' => __('Diners Club')
            ],
            [
                'value' => "jcb",
                'label' => __('JCB')
            ],
            [
                'value' => "mastercard",
                'label' => __('MasterCard')
            ],
            [
                'value' => "visa",
                'label' => __('Visa')
            ],
            [
                'value' => "cartes_bancaires",
                'label' => __('Cartes Bancaires')
            ],
        ];
    }
}
