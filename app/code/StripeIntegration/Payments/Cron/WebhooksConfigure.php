<?php

namespace StripeIntegration\Payments\Cron;

class WebhooksConfigure
{
    public $lastError = null;
    private $config;
    private $helper;
    private $webhooksSetup;
    private $appState;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\WebhooksSetup $webhooksSetup,
        \Magento\Framework\App\State $appState
    ) {
        $this->config = $config;
        $this->helper = $helper;
        $this->webhooksSetup = $webhooksSetup;
        $this->appState = $appState;
    }

    public function execute()
    {
        try
        {
            if ($this->webhooksSetup->isConfigureNeeded())
                $this->webhooksSetup->configure();
        }
        catch (\Exception $e)
        {
            $this->lastError = $e->getMessage();
            $this->helper->logError("Could not configure webhooks: " . $e->getMessage());
        }

        try
        {
            if ($this->appState->getMode() != \Magento\Framework\App\State::MODE_DEVELOPER)
            {
                $enabled = !!$this->config->getValue("payment/stripe_payments/webhook_origin_check", "default");
                if (!$enabled)
                {
                    $this->config->enableOriginCheck();
                    $this->config->clearCache("config");
                }
            }
        }
        catch (\Exception $e)
        {
            $this->lastError = $e->getMessage();
            $this->helper->logError("Could not enable origin check: " . $e->getMessage());
        }
    }
}
