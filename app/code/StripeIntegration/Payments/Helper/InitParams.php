<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;

class InitParams
{
    protected $setupIntents = [];
    private $helper;
    private $paymentMethodHelper;
    private $paymentElement;
    private $expressHelper;
    private $customer;
    private $localeHelper;
    private $config;
    private $serializer;

    public function __construct(
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Locale $localeHelper,
        \StripeIntegration\Payments\Helper\ExpressHelper $expressHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentElement $paymentElement,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper
    ) {
        $this->serializer = $serializer;
        $this->helper = $helper;
        $this->localeHelper = $localeHelper;
        $this->expressHelper = $expressHelper;
        $this->config = $config;
        $this->paymentElement = $paymentElement;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->customer = $helper->getCustomerModel();
    }

    public function getCheckoutParams()
    {
        if (!$this->config->isEnabled())
        {
            $params = [];
        }
        else if ($this->helper->isMultiShipping()) // Called by the UIConfigProvider
        {
            return $this->getMultishippingParams();
        }
        else
        {
            $params = [
                "apiKey" => $this->config->getPublishableKey(),
                "locale" => $this->localeHelper->getStripeJsLocale(),
                "appInfo" => $this->config->getAppInfo(true),
                "options" => [
                    "betas" => \StripeIntegration\Payments\Model\Config::BETAS_CLIENT,
                    "apiVersion" => $this->config->getStripeAPIVersion()
                ],
                "successUrl" => $this->helper->getUrl('stripe/payment/index'),
                "savedMethods" => $this->paymentElement->getSavedPaymentMethods(),
                "cvcIcon" => $this->paymentMethodHelper->getCVCIcon(),
                "isOrderPlaced" => $this->paymentElement->isOrderPlaced()
            ];

            if ($this->config->isVerticalLayout())
            {
                $params["layout"] = [
                    "type" => "accordion",
                    "defaultCollapsed" => false,
                    "radios" => true,
                    "spacedAccordionItems" => false
                ];
            }


            // When the wallet button is enabled at the checkout, we do not want to also display it inside the Payment Element, so we disable it there.
            if ($this->expressHelper->isEnabled("checkout_page"))
            {
                $params["wallets"] = [
                    "applePay" => "never",
                    "googlePay" => "never"
                ];
            }
            else
                $params["wallets"] = null;
        }

        return $this->serializer->serialize($params);
    }

    public function getAdminParams()
    {
        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "appInfo" => $this->config->getAppInfo(true)
        ];

        return $this->serializer->serialize($params);
    }

    public function getMultishippingParams()
    {
        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "appInfo" => $this->config->getAppInfo(true),
            "savedMethods" => $this->customer->getSavedPaymentMethods(null, true)
        ];

        return $this->serializer->serialize($params);
    }

    public function getMyPaymentMethodsParams($customerId)
    {
        if (!$this->config->isEnabled())
            return $this->serializer->serialize([]);

        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "currency" => strtolower($this->helper->getCurrentCurrencyCode()),
            "appInfo" => $this->config->getAppInfo(true),
            "options" => [
                "betas" => \StripeIntegration\Payments\Model\Config::BETAS_CLIENT,
                "apiVersion" => $this->config->getStripeAPIVersion()
            ],
            "returnUrl" => $this->helper->getUrl('stripe/customer/paymentmethods')
        ];

        return $this->serializer->serialize($params);
    }

    public function getWalletParams()
    {
        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "appInfo" => $this->config->getAppInfo(true),
            "options" => [
                "betas" => \StripeIntegration\Payments\Model\Config::BETAS_CLIENT,
                "apiVersion" => $this->config->getStripeAPIVersion()
            ]
        ];

        return $this->serializer->serialize($params);
    }
}
