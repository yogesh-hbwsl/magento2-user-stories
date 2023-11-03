<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\WebhookException;
use StripeIntegration\Payments\Exception\SilentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WebhooksSetup
{
    const VERSION = 12;

    public static $enabledEvents = [
        "charge.captured",
        "charge.refunded",
        "charge.succeeded",
        "checkout.session.expired",
        "checkout.session.completed",
        "customer.subscription.created",
        "customer.subscription.updated",
        "customer.subscription.deleted",
        "invoice.upcoming",
        "payment_intent.succeeded",
        "payment_intent.canceled",
        "payment_intent.partially_funded",
        "payment_intent.processing",
        "payment_intent.payment_failed",
        "payment_method.attached",
        "review.closed",
        "setup_intent.succeeded",
        "setup_intent.canceled",
        "setup_intent.setup_failed",
        "source.chargeable",
        "source.canceled",
        "source.failed",
        "invoice.paid",
        "invoice.payment_succeeded",
        "invoice.payment_failed",
        "invoice.voided",
        "product.created" // This is a dummy event for setting up webhooks
    ];

    public $configurations = null;
    public $errorMessages = [];
    public $successMessages = [];

    protected $output = null;

    private $logger;
    private $eventManager;
    private $cache;
    private $urlHelper;
    private $webhookCollectionFactory;
    private $webhooksHelper;
    private $stripeAccountFactory;
    private $webhooksLogger;
    private $storeManager;
    private $url;
    private $scopeConfig;
    private $config;
    private $webhookFactory;
    private $compare;

    public function __construct(
        \StripeIntegration\Payments\Logger\WebhooksLogger $webhooksLogger,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Url $urlHelper,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Model\Stripe\AccountFactory $stripeAccountFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\WebhookFactory $webhookFactory,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook\CollectionFactory $webhookCollectionFactory
    ) {
        $this->webhooksLogger = $webhooksLogger;
        $this->logger = $logger;
        $this->eventManager = $eventManager;
        $this->cache = $cache;
        $this->storeManager = $storeManager;
        $this->urlHelper = $urlHelper;
        $this->scopeConfig = $scopeConfig;
        $this->webhooksHelper = $webhooksHelper;
        $this->compare = $compare;
        $this->stripeAccountFactory = $stripeAccountFactory;
        $this->config = $config;
        $this->webhookFactory = $webhookFactory;
        $this->webhookCollectionFactory = $webhookCollectionFactory;
    }

    // Returns secret API keys for stores which are active, and for the Mode that they are configured in.
    public function getAllActiveAPIKeys()
    {
        return $this->getAllAPIKeys(true);
    }

    public function getAllAPIKeys($active = false)
    {
        $keys = [];
        $stores = $this->storeManager->getStores();

        foreach ($stores as $store) {
            if ($active && !$store->getIsActive())
                continue;

            $mode = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_mode", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store->getCode());
            $sk = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_{$mode}_sk", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store->getCode());
            $sk = (empty($sk) ? null : $this->config->decrypt($sk));
            $pk = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_{$mode}_pk", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store->getCode());
            $pk = (empty($pk) ? null : $this->config->decrypt($pk));

            if (!empty($sk) && !empty($pk)) {
                $keys[$sk] = $pk;
            }
        }

        return $keys;
    }

    public function configure()
    {
        $this->errorMessages = [];
        $this->successMessages = [];

        $error = null;

        if (!$this->config->canInitialize($error)) {
            $this->error($error);
            return;
        } else {
            $this->config->setAppInfo();
        }

        $keys = $this->getAllActiveAPIKeys();
        foreach ($keys as $secretKey => $publishableKey) {
            $account = $this->stripeAccountFactory->create(['secretKey' => $secretKey, 'publishableKey' => $publishableKey]);

            $url = $account->getDefaultWebhookEndpointOption();

            if (!$url) {
                $message = "Account {$account->getName()} cannot be configured, no valid URLs found.";
                $this->error($message);
                continue;
            }

            try {
                $webhookEndpoint = $account->configureWebhooks($url);
                $this->info("Configured webhook endpoint " . $webhookEndpoint->getName() . " for account " . $account->getName() . "");
            } catch (\Exception $e) {
                $this->error("Could not configure webhooks for account " . $account->getName() . ": " . $e->getMessage());
            }

            try {
                $deleted = $account->deleteUnknownWebhookEndpointsByUrl($url);
                if (!empty($deleted)) {
                    $ids = implode(", ", $deleted);
                    $this->info("Deleted duplicate webhook endpoint $url ($ids) for account " . $account->getName());
                }
            } catch (\Exception $e) {
                $this->error("Could not delete duplicate webhook endpoint $url - " . $e->getMessage());
            }
        }

        $this->cleanupOldWebhookEntries();
    }

    public function configureManually(InputInterface $input, OutputInterface $output)
    {
        $io = new \Symfony\Component\Console\Style\SymfonyStyle($input, $output);
        $this->errorMessages = [];
        $this->successMessages = [];

        $error = null;

        if (!$this->config->canInitialize($error)) {
            $output->writeln("<error>$error</error>");
            return;
        } else {
            $this->config->setAppInfo();
        }

        $keys = $this->getAllActiveAPIKeys();
        foreach ($keys as $secretKey => $publishableKey) {
            $account = $this->stripeAccountFactory->create(['secretKey' => $secretKey, 'publishableKey' => $publishableKey]);
            $options = $account->getPossibleWebhookEndpointOptions();

            $default = $account->getDefaultWebhookEndpointOption();
            if (!$default) {
                $message = "Account {$account->getName()} cannot be configured, no valid URLs found.";
                $output->writeln("<error>$message</error>");
                continue;
            }

            $prompt = "Select a preferred webhooks URL for account {$account->getName()}, or press ENTER to use the default";
            $url = $io->choice($prompt, $options, $default);

            try {
                $webhookEndpoint = $account->configureWebhooks($url);
                $output->writeln("<info>Configured webhook endpoint " . $webhookEndpoint->getName() . " for account " . $account->getName() . "</info>\n");
            } catch (\Exception $e) {
                $output->writeln("<error>" . $e->getMessage() . "</error>");
            }

            try {
                $deleted = $account->deleteUnknownWebhookEndpointsByUrl($url);
                if (!empty($deleted)) {
                    $ids = implode(", ", $deleted);
                    $output->writeln("<info>Deleted duplicate webhook endpoint $url ($ids) for account " . $account->getName() . "</info>\n");
                }
            } catch (\Exception $e) {
                $output->writeln("<error>Could not delete duplicate webhook endpoint $url - " . $e->getMessage() . "</error>");
            }
        }

        $this->cleanupOldWebhookEntries();
    }

    protected function cleanupOldWebhookEntries()
    {
        $webhooksCollection = $this->webhookCollectionFactory->create();

        foreach ($webhooksCollection as $webhook)
        {
            if ($webhook->isOutdated())
            {
                $webhook->delete();
            }
        }
    }

    public function getValidWebhookUrl($storeId)
    {
        try {
            $url = $this->getWebhookUrl($storeId);
            if ($this->isValidUrl($url))
                return $url;
        } catch (\Exception $e) {
            $this->log("Cannot generate webhooks URL: " . $e->getMessage());
        }

        return null;
    }

    public function getWebhookUrl($storeId)
    {
        $this->storeManager->setCurrentStore($storeId);
        $url = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true);

        if (empty($url))
            throw new \Exception("Please configure a store BASE URL.");

        $url = filter_var($url, FILTER_SANITIZE_URL);
        $url = rtrim(trim($url), "/");
        $url .= '/stripe/webhooks';
        return $url;
    }

    public function isValidUrl($url)
    {
        // Validate URL
        if (filter_var($url, FILTER_VALIDATE_URL) === false)
            return false;

        return true;
    }

    public function error($msg)
    {
        $count = count($this->errorMessages) + 1;

        $this->errorMessages[] = $msg;

        $this->log("Error $count: $msg");
    }

    public function info($msg)
    {
        $this->successMessages[] = $msg;

        $this->log($msg);
    }

    public function log($msg)
    {
        // Magento 2.0.0 - 2.4.3
        if (method_exists($this->webhooksLogger, 'addInfo'))
            $this->webhooksLogger->addInfo($msg);
        // Magento 2.4.4+
        else
            $this->webhooksLogger->info($msg);
    }

    protected function getStoreConfiguration($storeId, $store, $mode)
    {
        $config = $this->getStoreViewAPIKey($store, $mode);

        if (empty($config['api_keys']['sk']) || empty($config['api_keys']['pk']))
            return null;

        $url = $this->getValidWebhookUrl($storeId);
        if (!$url)
            return null;

        if (!$config['is_mode_selected'])
            return null;

        $config['url'] = $url;

        return $config;
    }

    public function getStoreViewAPIKeys()
    {
        $storeManagerDataList = $this->storeManager->getStores();
        $configurations = array();

        foreach ($storeManagerDataList as $storeId => $store) {
            // Test mode
            $config = $this->getStoreConfiguration($storeId, $store, 'test');

            if ($config)
                $configurations[$config['api_keys']['sk']] = $config;

            // Live mode
            $config = $this->getStoreConfiguration($storeId, $store, 'live');

            if ($config)
                $configurations[$config['api_keys']['sk']] = $config;
        }

        return $configurations;
    }

    public function getStoreViewAPIKey($store, $mode)
    {
        $secretKey = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_{$mode}_sk", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store['code']);
        if (empty($secretKey))
            return null;

        $storeSelectedMode = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_mode", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store['code']);

        return [
            'label' => $store['name'],
            'code' => $store['code'],
            'api_keys' => [
                'pk' => $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_{$mode}_pk", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store['code']),
                'sk' => $this->config->decrypt($secretKey)
            ],
            'mode' => $mode,
            'is_mode_selected' => ($mode == $storeSelectedMode),
            'mode_label' => ucfirst($mode) . " Mode"
        ];
    }

    protected function getConfiguredWebhooksForAPIKey($key)
    {
        $webhooks = [];
        if (empty($key))
            return $webhooks;

        \Stripe\Stripe::setApiKey($key);
        $data = \Stripe\WebhookEndpoint::all(['limit' => 100]);
        foreach ($data->autoPagingIterator() as $webhook) {
            if (stripos($webhook->url, "/stripe/webhooks") === false)
                continue;

            $webhooks[] = $webhook;
        }

        return $webhooks;
    }

    public function onWebhookCreated($event)
    {
        if (empty($event->data->object->metadata->webhook_id))
            return;

        $webhookId = $event->data->object->metadata->webhook_id;

        $webhook = $this->webhookFactory->create()->load($webhookId, 'webhook_id');

        if ($webhook->getId()) {
            $webhook->activate()->pong()->save();
        }
    }

    public function isConfigureNeeded()
    {
        $automaticConfigurationEnabled = $this->scopeConfig->getValue('stripe_settings/automatic_webhooks_configuration', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, 0);
        if (is_numeric($automaticConfigurationEnabled) && $automaticConfigurationEnabled == 0) {
            return false;
        }

        $error = null;

        if (!$this->config->canInitialize($error)) {
            $this->error($error);
            throw new SilentException($error);
        } else
            $this->config->setAppInfo();

        $keys = $this->getAllActiveAPIKeys();
        foreach ($keys as $secretKey => $publishableKey) {
            $webhookModel = $this->webhookFactory->create()->load($publishableKey, 'publishable_key');

            if (!$webhookModel->getId()) {
                return true;
            }

            if ($webhookModel->isOutdated()) {
                return true;
            }
        }

        return false;
    }
}
