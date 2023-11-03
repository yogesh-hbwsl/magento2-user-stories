<?php

namespace StripeIntegration\Payments\Model\Ui;

use Magento\Framework\Exception\LocalizedException;
use Magento\Checkout\Model\ConfigProviderInterface;
use StripeIntegration\Payments\Gateway\Http\Client\ClientMock;
use Magento\Framework\Locale\Bundle\DataBundle;
use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Model\PaymentMethod;
use StripeIntegration\Payments\Model\Config;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Class ConfigProvider
 */
class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'stripe_payments';
    const YEARS_RANGE = 15;

    private $assetRepo;
    private $cardIcons;
    private $config;
    private $customer;
    private $expressHelper;
    private $helper;
    private $initParams;
    private $localeHelper;
    private $logger;
    private $paymentIntent;
    private $paymentMethodHelper;
    private $request;
    private $scopeConfig;
    private $serializer;
    private $session;
    private $subscriptionsHelper;
    private $urlBuilder;
    private $response;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \Magento\Customer\Model\Session $session,
        \Magento\Framework\App\ResponseInterface $response,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Locale $localeHelper,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\ExpressHelper $expressHelper,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \StripeIntegration\Payments\Model\Adminhtml\Source\CardIconsSpecific $cardIcons,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\InitParams $initParams,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->request = $request;
        $this->assetRepo = $assetRepo;
        $this->serializer = $serializer;
        $this->session = $session;
        $this->response = $response;
        $this->config = $config;
        $this->localeHelper = $localeHelper;
        $this->helper = $helper;
        $this->expressHelper = $expressHelper;
        $this->customer = $helper->getCustomerModel();
        $this->paymentIntent = $paymentIntent;
        $this->cardIcons = $cardIcons;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->initParams = $initParams;
        $this->paymentMethodHelper = $paymentMethodHelper;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        $data = [];

        $data = [
            'payment' => [
                self::CODE => [
                    'enabled' => $this->config->isEnabled(),
                    'initParams' => $this->serializer->unserialize($this->initParams->getCheckoutParams()),
                    'icons' => $this->getIcons(),
                    'pmIcons' => $this->paymentMethodHelper->getPaymentMethodDetails(),
                    'elementOptions' => $this->getElementOptions(),
                    'hasTrialSubscriptions' => false,
                    'trialingSubscriptions' => null,
                    'module' => Config::module()
                ],
                'wallet_button' => [
                    'enabled' => $this->expressHelper->isEnabled('checkout_page'),
                    'initParams' => $this->serializer->unserialize($this->initParams->getWalletParams()),
                    'prapiTitle' => $this->helper->getPRAPIMethodType(),
                    'buttonConfig' => $this->config->getPRAPIButtonSettings(),
                ],
                'stripe_payments_bank_transfers' => [
                    'elementOptions' => $this->getBankTransfersElementOptions(),
                ]
            ]
        ];

        if ($this->config->isEnabled() && $this->config->isSubscriptionsEnabled())
        {
            // These are a bit more resource intensive, so we only want to run them if the module is enabled
            $data['payment'][self::CODE]['hasTrialSubscriptions'] = $this->helper->hasTrialSubscriptions();
            $data['payment'][self::CODE]['trialingSubscriptions'] = $this->subscriptionsHelper->getTrialingSubscriptionsAmounts();

            $subscriptionUpdateDetails = $this->getFrontendSubscriptionUpdateDetails();
            if ($subscriptionUpdateDetails)
            {
                $data['payment'][self::CODE]['subscriptionUpdateDetails'] = $subscriptionUpdateDetails;
            }
        }

        return $data;
    }

    protected function getElementOptions()
    {
        $options = [
            "mode" => "payment",
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "paymentMethodCreation" => "manual",
            "appearance" => [
                "theme" => "stripe",
                "variables" => [
                    "colorText" => "#32325d",
                    "fontFamily" => '"Open Sans","Helvetica Neue", Helvetica, Arial, sans-serif'
                ],
            ]
        ];

        if ($this->config->getPaymentAction() == "order")
        {
            $options["setupFutureUsage"] = "off_session";
        }

        if ($this->config->isEnabled() && $this->config->isSubscriptionsEnabled())
        {
            if ($this->helper->hasSubscriptions())
            {
                // Regular products may also exist in this cart. We still go for subscribe mode.
                $options["mode"] = "subscription";
            }
        }

        if ($options["mode"] == "payment")
        {
            $paymentMethodTypes = $this->paymentMethodHelper->getFilteredPaymentMethodTypes();
            if (!empty($paymentMethodTypes))
            {
                $options["payment_method_types"] = $paymentMethodTypes;
            }
        }

        $pmc = $this->config->getPaymentMethodConfiguration();
        if ($pmc)
        {
            $options['payment_method_configuration'] = $pmc;
        }

        return $options;
    }

    protected function getBankTransfersElementOptions()
    {
        $options = [
            "mode" => "payment",
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "paymentMethodCreation" => "manual",
            "appearance" => [
                "theme" => "stripe",
                "variables" => [
                    "colorText" => "#32325d",
                    "fontFamily" => '"Open Sans","Helvetica Neue", Helvetica, Arial, sans-serif'
                ],
            ],
            "payment_method_types" => ["customer_balance"]
        ];

        return $options;
    }

    protected function getFrontendSubscriptionUpdateDetails()
    {
        try
        {
            $subscriptionUpdateDetails = $this->helper->getCheckoutSession()->getSubscriptionUpdateDetails();
            if (!empty($subscriptionUpdateDetails['_data']['subscription_id']))
            {
                // Ensure that the subscription can be updated
                $subscription = $this->config->getStripeClient()->subscriptions->retrieve($subscriptionUpdateDetails['_data']['subscription_id'], []);
                if ($subscription->status != "active")
                    throw new LocalizedException(__("This subscription cannot be updated because it is not active."));

                // Ensure that the product is still in the cart
                if (empty($subscriptionUpdateDetails['_data']['product_ids']))
                    throw new \StripeIntegration\Payments\Exception\SilentException("No product IDs set.");

                $quote = $this->helper->getQuote();

                unset($subscriptionUpdateDetails['_data']); // Unset sensitive _data and return the remaining info for front-end display

                $subscriptionUpdateDetails["success_url"] = $this->helper->getUrl("stripe/customer/subscriptions", ["updateSuccess" => 1]);
                $subscriptionUpdateDetails["cancel_url"] = $this->helper->getUrl("stripe/customer/subscriptions", ["updateCancel" => 1]);
                $subscriptionUpdateDetails["is_virtual"] = $quote->getIsVirtual();
                return $subscriptionUpdateDetails;
            }

            return null;
        }
        catch (\StripeIntegration\Payments\Exception\SilentException $e)
        {
            $this->helper->logError("Canceling subscription update: " . $e->getMessage());
            $this->helper->getCheckoutSession()->unsSubscriptionUpdateDetails();
            return null;
        }
        catch (\Magento\Framework\Exception\LocalizedException $e)
        {
            $this->subscriptionsHelper->cancelSubscriptionUpdate(true);
            $this->helper->addError($e->getMessage());
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            $this->redirect('stripe/customer/subscriptions');
            return null;
        }
        catch (\Exception $e)
        {
            $this->helper->logError("Canceling subscription update: " . $e->getMessage());
            $this->helper->getCheckoutSession()->unsSubscriptionUpdateDetails();
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Retrieve url of a view file
     *
     * @param string $fileId
     * @param array $params
     * @return string
     */
    public function getViewFileUrl($fileId, array $params = [])
    {
        try {
            $params = array_merge(['_secure' => $this->request->isSecure()], $params);
            return $this->assetRepo->getUrlWithParams($fileId, $params);
        } catch (LocalizedException $e) {
            $this->logger->critical($e);
            return $this->urlBuilder->getUrl('', ['_direct' => 'core/index/notFound']);
        }
    }

    public function getIcons()
    {
        $icons = [];
        $displayIcons = $this->config->displayCardIcons();
        switch ($displayIcons)
        {
            // All
            case 0:
                $options = $this->cardIcons->toOptionArray();
                foreach ($options as $option)
                {
                    $code = $option["value"];
                    $icons[] = [
                        'code' => $code,
                        'name' => $option["label"],
                        'path' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/$code.svg")
                    ];
                }
                return $icons;
            // Specific
            case 1:
                $specific = explode(",", $this->config->getCardIcons());
                foreach ($specific as $code)
                {
                    if (empty($code))
                        continue;

                    $icons[] = [
                        'code' => $code,
                        'name' => null,
                        'path' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/$code.svg")
                    ];
                }
                return $icons;
            // Disabled
            default:
                return [];
        }
    }

    public function redirect($path)
    {
        $this->response->clearHeaders()->setNoCacheHeaders();
        $url = $this->helper->getUrl($path);
        $this->response->setRedirect($url)->sendResponse();
    }
}
