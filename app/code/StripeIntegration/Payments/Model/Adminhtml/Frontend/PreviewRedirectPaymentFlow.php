<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Frontend;

class PreviewRedirectPaymentFlow extends \Magento\Config\Block\System\Config\Form\Field
{
    protected $_template = 'StripeIntegration_Payments::config/preview_redirect_payment_flow.phtml';

    private $assetRepository;

    public function __construct(
        \Magento\Framework\View\Asset\Repository $assetRepository,
        \Magento\Backend\Block\Template\Context $context,
        array $data = []
    ) {
        $this->assetRepository = $assetRepository;

        parent::__construct($context, $data);
    }

    public function render(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getIconPreview()
    {
        return $this->assetRepository->getUrl("StripeIntegration_Payments::gif/redirect_payment_flow.gif");
    }

    public function getExternalUrl()
    {
        return "https://stripe.com/payments/checkout";
    }
}
