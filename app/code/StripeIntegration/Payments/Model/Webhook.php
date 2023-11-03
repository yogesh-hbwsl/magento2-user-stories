<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception;

class Webhook extends \Magento\Framework\Model\AbstractModel
{
    protected $compare;
    protected $storeManager;
    protected $scopeConfig;
    protected $config;
    protected $webhooksHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook $resource,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook\Collection $resourceCollection,
        array $data = []
    )
    {
        $this->compare = $compare;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->config = $config;
        $this->webhooksHelper = $webhooksHelper;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\Webhook');
    }

    public function pong()
    {
        $this->setLastEvent(time());
        return $this;
    }

    public function activate()
    {
        $this->setActive(1);
        return $this;
    }

    public function isOutdated()
    {
        if ($this->getConfigVersion() != \StripeIntegration\Payments\Helper\WebhooksSetup::VERSION) {
            return true;
        }

        $urls = $this->getAllStoreURLs();
        if (!in_array($this->getUrl(), $urls)) {
            return true;
        }

        $enabledEvents = json_decode($this->getEnabledEvents(), true);
        if (!$this->compare->areArrayValuesTheSame($enabledEvents, \StripeIntegration\Payments\Helper\WebhooksSetup::$enabledEvents)) {
            return true;
        }

        return false;
    }

    protected function getAllStoreURLs()
    {
        $urls = [];
        $stores = $this->storeManager->getStores();

        foreach ($stores as $store) {
            $url = $this->webhooksHelper->getValidWebhookUrl($store);

            if ($url) {
                $urls[$url] = $url;
            }
        }

        return $urls;
    }
}
