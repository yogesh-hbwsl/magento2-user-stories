<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Frontend;

use \Magento\Config\Block\System\Config\Form\Field;
use StripeIntegration\Payments\Helper\Data as DataHelper;

class PaymentMethodConfiguration extends Field
{
    protected $_template = 'StripeIntegration_Payments::config/payment_methods_configuration.phtml';

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        return parent::render($element);
    }

    protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        $options = $this->_getOptions();
        $value = $element->getValue();

        if (empty($value))
        {
            // Determine the value that should be pre-selected based on the is_default attribute
            foreach ($options as $option) {
                if (isset($option['is_default']) && $option['is_default']) {
                    $value = $option['value'];
                    break;
                }
            }
        }

        $useWebsiteValue = $element->getData('inherit') == 1; // Check if it's using website/default value
        $scope = $element->getScope();

        $select = $element->getForm()->addField(
            $element->getHtmlId() . '_select',
            'select',
            [
                'name' => $element->getName(),
                'values' => $options,
                'value' => $value,
                'label' => __('Payment method configuration'),
                'disabled' => $useWebsiteValue && ($scope != 'default')
            ]
        );

        // Combine the select HTML with the template HTML
        return $select->getElementHtml() . $this->_toHtml();
    }

    private function _getOptions()
    {
        $sourceModel = DataHelper::getSingleton(\StripeIntegration\Payments\Model\Adminhtml\Source\PaymentMethodConfiguration::class);
        return $sourceModel->toOptionArray();
    }
}
