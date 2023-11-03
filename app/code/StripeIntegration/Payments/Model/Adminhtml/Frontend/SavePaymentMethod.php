<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Frontend;

class SavePaymentMethod extends \Magento\Config\Block\System\Config\Form\Field
{
    private $config;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Backend\Block\Template\Context $context,
        array $data = []
    ) {
        $this->config = $config;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        if ($this->config->isAuthorizeOnly() && $this->config->retryWithSavedCard())
        {
            $element->setDisabled(true);
            return "<p>Enabled (via \"Expired authorizations\" setting)</p>";
        }

        return parent::_getElementHtml($element);
    }
}
