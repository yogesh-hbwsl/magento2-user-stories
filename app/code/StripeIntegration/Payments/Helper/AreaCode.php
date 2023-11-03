<?php

namespace StripeIntegration\Payments\Helper;

class AreaCode
{
    private $state;

    public function __construct(
        \Magento\Framework\App\State $state
    )
    {
        $this->state = $state;
    }

    public function getAreaCode()
    {
        try
        {
            return $this->state->getAreaCode();
        }
        catch (\Exception $e)
        {
            return null;
        }
    }

    // const AREA_GLOBAL = 'global';
    // const AREA_FRONTEND = 'frontend';
    // const AREA_ADMINHTML = 'adminhtml';
    // const AREA_DOC = 'doc';
    // const AREA_CRONTAB = 'crontab';
    // const AREA_WEBAPI_REST = 'webapi_rest';
    // const AREA_WEBAPI_SOAP = 'webapi_soap';
    // const AREA_GRAPHQL = 'graphql';
    public function setAreaCode($code = "global")
    {
        $areaCode = $this->getAreaCode();
        if (!$areaCode)
            $this->state->setAreaCode($code);
    }
}
