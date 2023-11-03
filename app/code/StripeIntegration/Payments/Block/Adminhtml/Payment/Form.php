<?php

namespace StripeIntegration\Payments\Block\Adminhtml\Payment;

use StripeIntegration\Payments\Helper\Logger;

// Payment method form in the Magento admin area
class Form extends \Magento\Payment\Block\Form\Cc
{
    private $assetRepository;
    private $formKey;

    protected $_template = 'form/stripe_payments.phtml';

    public function __construct(
        \Magento\Framework\View\Asset\Repository $repository,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Model\Config $paymentConfig,
        \Magento\Framework\Data\Form\FormKey $formKey,
        array $data = []
    ) {
        $this->assetRepository = $repository;
        $this->formKey = $formKey;

        parent::__construct($context, $paymentConfig, $data);
    }

    public function getFormKey()
    {
         return $this->formKey->getFormKey();
    }

    public function getAssetUrl($path)
    {
        try
        {
            return $this->assetRepository->createAsset($path)->getUrl();
        }
        catch (\Exception $e)
        {
            return null;
        }
    }
}
