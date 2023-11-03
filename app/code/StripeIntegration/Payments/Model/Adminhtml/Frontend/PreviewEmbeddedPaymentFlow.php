<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Frontend;

class PreviewEmbeddedPaymentFlow extends \Magento\Config\Block\System\Config\Form\Field
{
    protected $_template = 'StripeIntegration_Payments::config/preview_embedded_payment_flow.phtml';

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
        return $this->assetRepository->getUrl("StripeIntegration_Payments::svg/embedded_payment_flow.svg");
    }

    public function getExternalUrl()
    {
        return "https://stripe.com/docs/payments/payment-element";
    }
}
