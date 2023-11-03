<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Notifications;

class WebhooksUnconfigured implements \Magento\Framework\Notification\MessageInterface
{
    public $configurations = null;
    public $displayedText = null;
    private $config;
    private $logHandler;
    private $webhookModel;
    private $webhooksCollection;
    private $storeManager;
    private $request;
    private $urlBuilder;

    public function __construct(
        \StripeIntegration\Payments\Logger\Handler $logHandler,
        \StripeIntegration\Payments\Model\Webhook $webhookModel,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook\Collection $webhooksCollection,
        \StripeIntegration\Payments\Helper\WebhooksSetup $webhooksSetup,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Framework\UrlInterface $urlBuilder
    ) {
        $this->config = $config;
        $this->urlBuilder = $urlBuilder;

        if (!class_exists('Stripe\Stripe'))
        {
            $this->displayedText = "The Stripe PHP library has not been installed. Please follow the <a href=\"https://stripe.com/docs/plugins/magento/install#manual\" target=\"_blank\" rel=\"noopener noreferrer\">installation instructions</a> to install the dependency.";
            return;
        }

        if (version_compare(\Stripe\Stripe::VERSION, \StripeIntegration\Payments\Model\Config::$minStripePHPVersion) < 0)
        {
            $version = \StripeIntegration\Payments\Model\Config::$moduleVersion;
            $libVersion = $this->config->getComposerRequireVersion();
            $currentVersion = \Stripe\Stripe::VERSION;
            $this->displayedText = "Stripe Payments v$version now depends on Stripe PHP library v$libVersion. You currently have v$currentVersion installed. Please upgrade your installed Stripe PHP library with the command: composer require stripe/stripe-php:^$libVersion";
            return;
        }

        $this->logHandler = $logHandler;
        $this->webhookModel = $webhookModel;
        $this->webhooksCollection = $webhooksCollection;
        $this->storeManager = $storeManager;

        $stores = $this->storeManager->getStores();
        $configurations = array();

        foreach ($stores as $storeId => $store)
        {
            $mode = $this->config->getConfigData("stripe_mode", "basic", $storeId);
            if (empty($mode))
                continue;
            else
                $configurations[] = $webhooksSetup->getStoreViewAPIKey($store, $mode);
        }

        $allWebhooks = $this->webhooksCollection->getAllWebhooks(true);

        $instructions = "You can configure webhooks manually with the following command: <code style=\"margin-left: 5px; color: brown\">bin/magento stripe:webhooks:configure</code>";

        if ($allWebhooks->count() == 0)
        {
            $this->displayedText = "Stripe webhooks could not be configured automatically. $instructions";

            return;
        }

        $activePublishableKeys = [];
        $staleWebhookPublishableKeys = [];
        $inactiveStores = [];
        $staleWebhookStores = [];

        // Figure out active, duplicate and stale webhooks
        foreach ($allWebhooks as $webhook)
        {
            $key = $webhook->getPublishableKey();

            $createdAtTimestamp = strtotime($webhook->getCreatedAt());
            $wasJustCreated = ((time() - $createdAtTimestamp) <= 300);
            $inactivityPeriod = (time() - $webhook->getLastEvent());
            if ($webhook->getActive() > 0 || ($webhook->getActive() == 0 && $wasJustCreated))
                $activePublishableKeys[$key] = $key;

            $tenHours = 10 * 60 * 60;
            if ($webhook->getActive() > 0 && $inactivityPeriod > $tenHours && !$wasJustCreated)
                $staleWebhookPublishableKeys[$key] = $key;

            if ($webhook->getConfigVersion() < \StripeIntegration\Payments\Helper\WebhooksSetup::VERSION)
            {
                $version = \StripeIntegration\Payments\Model\Config::$moduleVersion;
                $this->displayedText = "Stripe Payments v$version has added new webhook events or is using a newer webhooks API. $instructions";

                return;
            }
        }

        foreach ($configurations as $configuration)
        {
            if (empty($configuration['api_keys']['pk']))
                continue;

            if (!in_array($configuration['api_keys']['pk'], $activePublishableKeys))
                $inactiveStores[] = $configuration;

            if (in_array($configuration['api_keys']['pk'], $staleWebhookPublishableKeys))
                $staleWebhookStores[] = $configuration;
        }

        if (!empty($inactiveStores))
        {
            $storeNames = [];

            foreach ($inactiveStores as $store) {
                $storeNames[] = $store['label'] . " (" . $store['mode_label'] . ")";
            }

            $storeNamesText = implode(", ", $storeNames);

            $this->displayedText = "Stripe webhooks could not be configured automatically for: $storeNamesText - $instructions";

            return;
        }

        if (!empty($staleWebhookStores))
        {
            $storeNames = [];

            foreach ($staleWebhookStores as $store) {
                $storeNames[] = $store['label'] . " (" . $store['mode_label'] . ")";
            }

            $storeNamesText = implode(", ", $storeNames);

            $this->displayedText = "No webhook events have been received for: $storeNamesText - Please ensure that your webhooks URL is externally accessible and your cron jobs are running.";

            return;
        }
    }

    public function getUrl($path)
    {
        return $this->urlBuilder->getUrl($path, ['_secure' => $this->request->isSecure()]);
    }

    public function replaceLastOccuranceOf($search, $replace, $subject)
    {
        $pos = strrpos($subject, $search);

        if($pos !== false)
        {
            $subject = substr_replace($subject, $replace, $pos, strlen($search));
        }

        return $subject;
    }

    public function getIdentity()
    {
        return 'stripe_payments_notification_webhooks_unconfigured';
    }

    public function isDisplayed()
    {
        return !empty($this->displayedText);
    }

    public function getText()
    {
        return $this->displayedText;
    }

    public function getSeverity()
    {
        // SEVERITY_CRITICAL, SEVERITY_MAJOR, SEVERITY_MINOR, SEVERITY_NOTICE
        return self::SEVERITY_MAJOR;
    }
}
