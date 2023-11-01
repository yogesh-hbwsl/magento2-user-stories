<?php

namespace Yogesh\Mod1\Controller\Adminhtml\ShowText;

use Magento\Framework\App\ActionInterface;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Index implements ActionInterface
{
    public $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function execute()
    {
        $isEnabled =  $this->scopeConfig->getValue('general/parameters/enable', ScopeInterface::SCOPE_STORE);
        if ($isEnabled) {
            $text = $this->scopeConfig->getValue('general/parameters/display_text', ScopeInterface::SCOPE_STORE);
            if (empty($text)) {
                echo "Text field is empty";
            } else {
                echo $text;
            }
        } else {
            echo "Not enabled";
        }
        exit;
    }
}
