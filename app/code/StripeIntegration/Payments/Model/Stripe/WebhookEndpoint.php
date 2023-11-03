<?php

namespace StripeIntegration\Payments\Model\Stripe;

class WebhookEndpoint extends StripeObject
{
    protected $objectSpace = 'webhookEndpoints';
    protected $stripeClient = null;
    protected $publishableKey = null;

    protected function isInitialized()
    {
        return $this->getId() && $this->stripeClient && !empty($this->publishableKey);
    }

    protected function getCreateData($url)
    {
        return [
            'url' => $url,
            'api_version' => \StripeIntegration\Payments\Model\Config::STRIPE_API,
            'connect' => false,
            'enabled_events' => \StripeIntegration\Payments\Helper\WebhooksSetup::$enabledEvents,
        ];
    }

    protected function getUpdateData($url = null)
    {
        if (!$this->isInitialized())
        {
            throw new \Exception("Cannot update an uninitialized webhook object");
        }

        return [
            'url' => ($url ? $url : $this->object->url),
            'enabled_events' => \StripeIntegration\Payments\Helper\WebhooksSetup::$enabledEvents,
        ];
    }

    public function fromStripeObject($webhookEndpoint, $stripeClient, $publishableKey)
    {
        $this->object = $webhookEndpoint;
        $this->stripeClient = $stripeClient;
        $this->publishableKey = $publishableKey;

        return $this;
    }

    public function fromUrl($url, $stripeClient, $publishableKey)
    {
        $this->stripeClient = $stripeClient;
        $this->publishableKey = $publishableKey;

        $data = $this->getCreateData($url);

        $this->object = $stripeClient->webhookEndpoints->create($data);

        $webhookFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\WebhookFactory::class);
        $entry = $webhookFactory->create()->load($this->getId(), "webhook_id");
        $entry->addData([
            "config_version" => \StripeIntegration\Payments\Helper\WebhooksSetup::VERSION,
            "webhook_id" => $this->object->id,
            "publishable_key" => $this->publishableKey,
            "live_mode" => $this->object->livemode,
            "api_version" => $this->object->api_version,
            "url" => $this->object->url,
            "enabled_events" => json_encode($this->object->enabled_events),
            "secret" => $this->object->secret
        ]);

        $entry->save();

        $this->activate();

        return $this;
    }

    // Checks if we have a record of this endpoint in the database
    public function isKnown()
    {
        $localRecord = $this->getLocalRecord();

        if (!$localRecord)
        {
            return false;
        }
        else
        {
            return true;
        }
    }

    // Loads the local record from the database for this webhook endpoint
    public function getLocalRecord()
    {
        if (!$this->isInitialized())
        {
            throw new \Exception("Webhook object has not been initialized.");
        }

        $webhookFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\WebhookFactory::class);
        $entry = $webhookFactory->create()->load($this->getId(), "webhook_id");

        if ($entry && $entry->getId())
        {
            return $entry;
        }

        return null;
    }

    public function canUpdate()
    {
        if (!$this->isKnown())
            return false;

        if ($this->object->api_version != \StripeIntegration\Payments\Model\Config::STRIPE_API)
            return false;

        return true;
    }

    public function update($url = null)
    {
        $localRecord = $this->getLocalRecord();
        $updateData = $this->getUpdateData($url);

        $this->object = $this->stripeClient->webhookEndpoints->update($this->getId(), $updateData);

        $localRecord->addData([
            "config_version" => \StripeIntegration\Payments\Helper\WebhooksSetup::VERSION,
            "webhook_id" => $this->object->id,
            "publishable_key" => $this->publishableKey,
            "live_mode" => $this->object->livemode,
            "api_version" => $this->object->api_version,
            "url" => $this->object->url,
            "enabled_events" => json_encode($this->object->enabled_events),
        ]);

        $localRecord->save();

        return $this;
    }

    public function destroy()
    {
        $localRecord = $this->getLocalRecord();
        $this->stripeClient->webhookEndpoints->delete($this->getId(), []);
        $localRecord->delete();
        $this->object = null;

        return null;
    }

    public function activate()
    {
        $product = $this->stripeClient->products->create([
           'name' => 'Webhook Configuration',
           'type' => 'service',
           'metadata' => [
                "webhook_id" => $this->getId()
           ]
        ]);
        try
        {
            $product->delete();
        }
        catch (\Exception $e) { }
    }

    public function getUrl()
    {
        if (empty($this->object->url))
        {
            throw new \Exception("No url exists on uninitialized webhook object.");
        }

        return $this->object->url;
    }

    public function getName()
    {
        if (!empty($this->object->url))
        {
            return "{$this->object->url} ({$this->object->id})";
        }

        return $this->object->id;
    }
}
