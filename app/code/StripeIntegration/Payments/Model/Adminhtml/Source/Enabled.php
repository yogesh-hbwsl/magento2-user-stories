<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

class Enabled extends \Magento\Eav\Model\Entity\Attribute\Source\AbstractSource
{
    public function toOptionArray()
    {
        return array(
            array(
                'value' => 0,
                'label' => __('Disabled')
            ),
            array(
                'value' => 1,
                'label' => __('Enabled')
            )
        );
    }

    public function getAllOptions()
    {
        return $this->toOptionArray();
    }
}
