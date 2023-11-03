<?php

namespace StripeIntegration\Payments\Helper;

class WebhookEvent
{
    public $stripeClient = null;

    private $config;
    private $webhooksSetup;

    public function __construct(
        \StripeIntegration\Payments\Helper\WebhooksSetup $webhooksSetup,
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $this->webhooksSetup = $webhooksSetup;
        $this->config = $config;
    }

    public function validate($eventId)
    {
        if (empty($eventId))
            return false;

        if (strpos($eventId, "evt_") !== 0)
            return false;

        return true;
    }

    public function initStripeClientForStore($io)
    {
        $this->stripeClient = null;

        $configurations = $this->webhooksSetup->getStoreViewAPIKeys();
        $options = [];
        $default = null;

        foreach ($configurations as $configuration)
        {
            if (!$default)
                $default = $configuration['code'];

            $options[$configuration['code']] = "{$configuration['label']} ({$configuration['mode_label']}) - {$configuration['url']}";
        }

        if (count($options) > 1)
            $selection = $io->choice('Select a store to process webhooks for', $options, $default);
        else
            $selection = $default;

        try
        {
            $this->config->reInitStripeFromStoreCode($selection);
            $this->stripeClient = $this->config->getStripeClient();
        }
        catch (\Exception $e)
        {
            $io->writeln("<error>{$e->getMessage()}</error>");
        }

        return null;
    }

    public function initStripeClientForEventID($eventId)
    {
        $this->stripeClient = null;

        if (!$this->validate($eventId))
            return null;

        $configurations = $this->webhooksSetup->getStoreViewAPIKeys();

        foreach ($configurations as $configuration)
        {
            try
            {
                $this->config->reInitStripeFromStoreCode($configuration['code']);
                $event = $this->config->getStripeClient()->events->retrieve($eventId, []);
                $this->stripeClient = $this->config->getStripeClient();
                return $event;
            }
            catch (\Exception $e)
            {
                continue;
            }
        }

        return null;
    }

    public function getEvent($eventId)
    {
        if (empty($this->stripeClient))
            return $this->initStripeClientForEventID($eventId);

        if (!$this->validate($eventId))
            return null;

        try
        {
            return $this->stripeClient->events->retrieve($eventId, []);
        }
        catch (\Exception $e)
        {
            return null;
        }
    }

    public function getEventRange($fromTimestamp, $toTimestamp)
    {
        if (empty($this->stripeClient))
            return [];

        try
        {
            return $this->stripeClient->events->all(['limit' => 100, 'created' => ['gte' => $fromTimestamp, 'lte' => $toTimestamp]]);
        }
        catch (\Exception $e)
        {
            return [];
        }
    }
}
