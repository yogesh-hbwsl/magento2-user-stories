<?php

namespace Yogesh\Mod1\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    const TEXT_FIELD = 'general/parameters/display_text';
    const STATE = 'general/parameters/enable';

    protected $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function getText()
    {
        return $this->scopeConfig->getValue(self::TEXT_FIELD, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    public function getState()
    {
        return $this->scopeConfig->getValue(self::STATE, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
}
