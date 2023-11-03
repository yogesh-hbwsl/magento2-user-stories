<?php

namespace StripeIntegration\Payments\Model\Stripe;

class Account
{
    protected $stripeClient;
    protected $account;
    protected $stores = [];
    protected $webhookEndpoints = [];

    private $publishableKey;
    private $storeManager;
    private $scopeConfig;
    private $config;
    private $webhooksHelper;
    private $webhookEndpointFactory;

    public function __construct(
        $secretKey,
        $publishableKey,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Stripe\WebhookEndpointFactory $webhookEndpointFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    )
    {
        $this->webhooksHelper = $webhooksHelper;
        $this->config = $config;
        $this->webhookEndpointFactory = $webhookEndpointFactory;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;

        $this->initializeStripeClient($secretKey);
        $this->account = $this->stripeClient->accounts->retrieve();
        $this->publishableKey = $publishableKey;
        $this->initializeStores($secretKey);
    }

    protected function initializeStripeClient($secretKey)
    {
        $this->stripeClient = new \Stripe\StripeClient([
            "api_key" => $secretKey,
            "stripe_version" => \StripeIntegration\Payments\Model\Config::STRIPE_API
        ]);
    }

    protected function initializeStores($secretKey)
    {
        $stores = $this->storeManager->getStores();

        foreach ($stores as $store)
        {
            if (!$store->getIsActive())
                continue;

            $mode = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_mode", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store->getCode());
            $key = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_{$mode}_sk", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store->getCode());
            $key = $this->config->decrypt($key);

            if (!empty($key) && $key == $secretKey)
            {
                $this->stores[] = $store;
            }
        }
    }

    public function getName()
    {
        if (isset($this->account->settings->dashboard->display_name))
        {
            return $this->account->settings->dashboard->display_name . " (" . $this->getId() . ")";
        }
        else
        {
            return $this->getId();
        }
    }

    public function getId()
    {
        return isset($this->account->id) ? $this->account->id : "<NO_ID>";
    }

    public function getPossibleWebhookEndpointURLs()
    {
        $urls = [];

        foreach ($this->stores as $store)
        {
            $url = $this->webhooksHelper->getValidWebhookUrl($store);
            if ($url)
            {
                if (isset($urls[$url]))
                {
                    $urls[$url]['used_by'][] = $store->debug();
                }
                else
                {
                    $urls[$url] = [
                        'url' => $url,
                        'used_by' => [ $store->debug() ]
                    ];
                }
            }
        }

        return $urls;
    }

    // These are formatted for I/O
    public function getPossibleWebhookEndpointOptions()
    {
        $urls = $this->getPossibleWebhookEndpointURLs();

        $options = [];

        foreach ($urls as $url => $details)
        {
            $storeNames = [];
            foreach ($details['used_by'] as $store)
            {
                $storeNames[] = $store['name'] . " (" . $store['code'] . ")";
            }
            $options[$url] = implode(", ", $storeNames);
        }

        return $options;
    }

    public function getDefaultWebhookEndpointOption()
    {
        $options = $this->getPossibleWebhookEndpointOptions();

        if (count($options) == 1)
        {
            foreach ($options as $url => $option)
            {
                return $url;
            }
        }
        else if (count($options) > 1)
        {
            // For now
            foreach (array_reverse($options) as $url => $option)
            {
                return $url;
            }
        }

        return null;
    }

    public function getWebhookEndpoints()
    {
        if (!empty($this->webhookEndpoints))
        {
            return $this->webhookEndpoints;
        }

        $endpoints = $this->stripeClient->webhookEndpoints->all(['limit' => 20]);

        foreach ($endpoints->autoPagingIterator() as $endpoint)
        {
            $webhookEndpoint = $this->webhookEndpointFactory->create()->fromStripeObject($endpoint, $this->stripeClient, $this->publishableKey);

            $this->webhookEndpoints[$endpoint->id] = $webhookEndpoint;
        }

        return $this->webhookEndpoints;
    }

    // Returns all webhook endpoints in this account for which we have a local record in the database
    public function getKnownWebhookEndpoints()
    {
        $webhookEndpoints = [];
        $endpoints = $this->getWebhookEndpoints();

        foreach ($endpoints as $id => $endpoint)
        {
            if ($endpoint->isKnown())
            {
                $webhookEndpoints[$id] = $endpoint;
            }
        }

        return $webhookEndpoints;
    }

    protected function deleteWebhookEndpoint(\StripeIntegration\Payments\Model\Stripe\WebhookEndpoint $endpoint)
    {
        $id = $endpoint->getId();

        $endpoint->destroy();

        unset($this->webhookEndpoints[$id]);

        return $id;
    }

    public function deleteUnknownWebhookEndpointsByUrl($url)
    {
        $endpoints = $this->getWebhookEndpoints();

        $deleted = [];

        foreach ($endpoints as $id => $endpoint)
        {
            if ($endpoint->getUrl() == $url && !$endpoint->isKnown())
            {
                $this->stripeClient->webhookEndpoints->delete($id, []);
                $deleted[] = $id;
            }
        }

        foreach ($deleted as $id)
        {
            unset($this->webhookEndpoints[$id]);
        }

        return $deleted;
    }

    public function configureWebhooks($url)
    {
        $knownWebhookEndpoints = $this->getKnownWebhookEndpoints();

        if (empty($knownWebhookEndpoints))
        {
            return $this->webhookEndpointFactory->create()->fromUrl($url, $this->stripeClient, $this->publishableKey);
        }

        $updatedEndpoint = null;
        foreach ($knownWebhookEndpoints as $webhookEndpoint)
        {
            if ($webhookEndpoint->canUpdate() && !$updatedEndpoint)
            {
                // We update the very first known endpoint for this account which can be updated
                $updatedEndpoint = $webhookEndpoint->update($url);
            }
            else
            {
                // We destroy all other known endpoints for this account
                $this->deleteWebhookEndpoint($webhookEndpoint);
            }
        }

        if ($updatedEndpoint)
        {
            return $updatedEndpoint;
        }
        else
        {
            return $this->webhookEndpointFactory->create()->fromUrl($url, $this->stripeClient, $this->publishableKey);
        }
    }

    public function getStripeObject()
    {
        return $this->account;
    }
}
