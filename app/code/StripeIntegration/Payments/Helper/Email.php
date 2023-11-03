<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\CouldNotSaveException;

class Email
{
    private $scopeConfig;
    private $transportBuilder;
    private $helper;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder,
        \StripeIntegration\Payments\Helper\Generic $helper
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->transportBuilder = $transportBuilder;
        $this->helper = $helper;
    }

    public function getEmail($identifier)
    {
        return $this->scopeConfig->getValue("trans_email/ident_{$identifier}/email", \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    public function getName($identifier)
    {
        return $this->scopeConfig->getValue("trans_email/ident_{$identifier}/name", \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    public function send($template, $senderName, $senderEmail, $recepientName, $recepientEmail, $templateVars, $areaCode = 'frontend', $storeId = null)
    {
        try
        {
            if (empty($storeId))
                $storeId = $this->helper->getStoreId();

            $sender = [
                'name' => $senderName,
                'email' => $senderEmail
            ];

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($template)
                ->setTemplateOptions([ 'area' => $areaCode, 'store' => $storeId ])
                ->setTemplateVars($templateVars)
                ->setFromByScope($sender)
                ->addTo($recepientEmail, $recepientName)
                ->getTransport();

            $transport->sendMessage();

            return true;
        }
        catch (\Exception $e)
        {
            $this->helper->logError("Could not send email: " . $e->getMessage());
        }

        return false;
    }
}
