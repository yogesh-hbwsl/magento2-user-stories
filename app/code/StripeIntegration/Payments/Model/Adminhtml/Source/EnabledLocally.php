<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

class EnabledLocally extends \Magento\Eav\Model\Entity\Attribute\Source\AbstractSource
{
    public function toOptionArray()
    {
        return array(
            array(
                'value' => 0,
                'label' => __('Use config settings')
            ),
            array(
                'value' => 1,
                'label' => __('Disabled')
            ),
            array(
                'value' => 2,
                'label' => __('Enabled')
            )
        );
    }

    public function getAllOptions()
    {
        return $this->toOptionArray();
    }
}
