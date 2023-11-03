<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Model\ResourceModel\StripePaymentMethod as ResourceStripePaymentMethod;
use StripeIntegration\Payments\Model\StripePaymentMethodFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Framework\App\State;

class PaymentMethod
{
    protected $methodDetails = [];
    protected $themeModel = null;
    const CAN_BE_SAVED_ON_SESSION = [
        'acss_debit',
        'au_becs_debit',
        'boleto',
        'card',
        'sepa_debit',
        'us_bank_account' // ACHv2
    ];
    const CAN_BE_SAVED_OFF_SESSION = [ // Do not add methods that can be saved on_session here, see Model/PaymentIntent.php::getPaymentMethodOptions()
        'bancontact',
        'ideal',
        'sofort'
    ];
    const SUPPORTS_SUBSCRIPTIONS = [
        'card',
        'sepa_debit',
        'us_bank_account' // ACHv2
    ];
    const SETUP_INTENT_PAYMENT_METHOD_OPTIONS = [
        'acss_debit',
        'card',
        'sepa_debit',
        'us_bank_account' // ACHv2
    ];
    const CAN_AUTHORIZE_ONLY = [
        'card',
        'link',
        'afterpay_clearpay',
        'klarna'
    ];
    const REQUIRES_VOUCHER_PAYMENT = [
        'boleto',
        'oxxo',
        'konbini'
    ];

    const STRIPE_CHECKOUT_ON_SESSION_PM = [
        'acss_debit',
        'bacs_debit',
        'boleto',
        'card',
        'cashapp',
        'sepa_debit',
        'us_bank_account'
    ];

    const STRIPE_CHECKOUT_OFF_SESSION_PM = [
        'link',
        'paypal'
    ];

    const STRIPE_CHECKOUT_NONE_PM = [
        'affirm',
        'afterpay_clearpay',
        'alipay',
        'au_becs_debit',
        'bancontact',
        'eps',
        'fpx',
        'giropay',
        'grabpay',
        'ideal',
        'klarna',
        'konbini',
        'oxxo',
        'p24',
        'paynow',
        'sofort'
    ];

    private $dataHelper;
    private $request;
    private $assetRepo;
    private $scopeConfig;
    private $storeManager;
    private $themeProvider;

    protected $stripePaymentMethodFactory;

    protected $resourceStripePaymentMethod;

    protected $json;

    private $checkoutSession;

    protected $orderExtensionFactory;

    private $appEmulation;

    private $state;

    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\View\Design\Theme\ThemeProviderInterface $themeProvider,
        \Magento\Checkout\Model\Session $checkoutSession,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        ResourceStripePaymentMethod $resourceStripePaymentMethod,
        StripePaymentMethodFactory $stripePaymentMethodFactory,
        Json $json,
        OrderExtensionFactory $orderExtensionFactory,
        \Magento\Store\Model\App\Emulation $appEmulation,
        State $state
    ) {
        $this->request = $request;
        $this->assetRepo = $assetRepo;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->themeProvider = $themeProvider;
        $this->checkoutSession = $checkoutSession;
        $this->dataHelper = $dataHelper;
        $this->stripePaymentMethodFactory = $stripePaymentMethodFactory;
        $this->resourceStripePaymentMethod = $resourceStripePaymentMethod;
        $this->json = $json;
        $this->orderExtensionFactory = $orderExtensionFactory;
        $this->appEmulation = $appEmulation;
        $this->state = $state;
    }

    public function getCardIcon($brand)
    {
        $icon = $this->getPaymentMethodIcon($brand);
        if ($icon)
            return $icon;

        return $this->getPaymentMethodIcon('generic');
    }

    public function getCardLabel($card, $hideLast4 = false, $array = false)
    {
        if ($array) {
            if (!empty($card['last4']) && !$hideLast4)
                return __("•••• %1", $card['last4']);

            if (!empty($card['brand']))
                return $this->getCardName($card['brand']);
        } else {
            if (!empty($card->last4) && !$hideLast4)
                return __("•••• %1", $card->last4);

            if (!empty($card->brand))
                return $this->getCardName($card->brand);
        }

        return __("Card");
    }

    protected function getCardName($brand)
    {
        if (empty($brand))
            return "Card";

        $details = $this->getPaymentMethodDetails();
        if (isset($details[$brand]))
            return $details[$brand]['name'];

        return ucfirst($brand);
    }

    public function getIcon($method, $format = null)
    {
        if (is_array($method)) {
            $method = (object) $method;
        }
        $type = $method->type;

        $defaultIcon = $this->getPaymentMethodIcon($type);
        if ($defaultIcon)
        {
            $icon = $defaultIcon;
        }
        else if ($type == "card" && !empty($method->card->brand))
        {
            $icon = $this->getCardIcon($method->card->brand);
        }
        else
        {
            $icon = $this->getPaymentMethodIcon("bank");
        }

        if ($format)
            $icon = str_replace(".svg", ".$format", $icon);

        return $icon;
    }

    public function getPaymentMethodIcon($code)
    {
        $details = $this->getPaymentMethodDetails();
        if (isset($details[$code]))
            return $details[$code]['icon'];

        return null;
    }

    public function getPaymentMethodName($code)
    {
        $details = $this->getPaymentMethodDetails();

        if (isset($details[$code]))
            return $details[$code]['name'];

        return ucwords(str_replace("_", " ", $code));
    }

    public function getCVCIcon()
    {
        return $this->getViewFileUrl("StripeIntegration_Payments::img/icons/cvc.svg");
    }

    public function getPaymentMethodDetails()
    {
        if (!empty($this->methodDetails))
            return $this->methodDetails;

        return $this->methodDetails = [
            // APMs
            'acss_debit' => [
                'name' => "ACSS Direct Debit / Canadian PADs",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'afterpay_clearpay' => [
                'name' => "Afterpay / Clearpay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/afterpay_clearpay.svg")
            ],
            'alipay' => [
                'name' => "Alipay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/alipay.svg")
            ],
            'bacs_debit' => [
                'name' => "BACS Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bacs_debit.svg")
            ],
            'au_becs_debit' => [
                'name' => "BECS Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'bancontact' => [
                'name' => "Bancontact",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bancontact.svg")
            ],
            'boleto' => [
                'name' => "Boleto",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/boleto.svg")
            ],
            'customer_balance' => [
                'name' => "Bank transfer",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'eps' => [
                'name' => 'EPS',
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/eps.svg")
            ],
            'fpx' => [
                'name' => "FPX",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/fpx.svg")
            ],
            'giropay' => [
                'name' => "Giropay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/giropay.svg")
            ],
            'grabpay' => [
                'name' => "GrabPay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/grabpay.svg")
            ],
            'ideal' => [
                'name' => "iDEAL",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/ideal.svg")
            ],
            'klarna' => [
                'name' => "Klarna",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/klarna.svg")
            ],
            'konbini' => [
                'name' => "Konbini",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/konbini.svg")
            ],
            'paypal' => [
                'name' => "",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/paypal.svg")
            ],
            'multibanco' => [
                'name' => "Multibanco",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/multibanco.svg")
            ],
            'p24' => [
                'name' => "P24",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/p24.svg")
            ],
            'sepa_debit' => [
                'name' => "SEPA Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/sepa_debit.svg")
            ],
            'sepa_credit' => [
                'name' => "SEPA Credit Transfer",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/sepa_credit.svg")
            ],
            'sofort' => [
                'name' => "SOFORT",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/klarna.svg")
            ],
            'wechat' => [
                'name' => "WeChat Pay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/wechat.svg")
            ],
            'ach_debit' => [
                'name' => "ACH Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'us_bank_account' => [ // ACHv2
                'name' => "ACH Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'oxxo' => [
                'name' => "OXXO",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/oxxo.svg")
            ],
            'paynow' => [
                'name' => "PayNow",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/paynow.svg")
            ],
            'link' => [
                'name' => 'Link',
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/link.svg")
            ],
            'bank' => [
                'name' => "",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'google_pay' => [
                'name' => "Google Pay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/google_pay.svg")
            ],
            'apple_pay' => [
                'name' => "Apple Pay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/apple_pay.svg")
            ],

            // Cards
            'amex' => [
                'name' => "American Express",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/amex.svg")
            ],
            'cartes_bancaires' => [
                'name' => "Cartes Bancaires",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/cartes_bancaires.svg")
            ],
            'diners' => [
                'name' => "Diners Club",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/diners.svg")
            ],
            'discover' => [
                'name' => "Discover",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/discover.svg")
            ],
            'generic' => [
                'name' => "",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/generic.svg")
            ],
            'jcb' => [
                'name' => "JCB",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/jcb.svg")
            ],
            'mastercard' => [
                'name' => "MasterCard",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/mastercard.svg")
            ],
            'visa' => [
                'name' => "Visa",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/visa.svg")
            ],
            'unionpay' => [
                'name' => "UnionPay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/unionpay.svg")
            ]
        ];
    }

    public function isCard1NewerThanCard2($card1expMonth, $card1expYear, $card2expMonth, $card2expYear)
    {

    }

    public function getPaymentMethodLabel($method)
    {
        $type = $method->type;
        $methodName = $this->getPaymentMethodName($type);
        $details = $method->{$type};

        if ($type == "card")
        {
            return $this->getCardLabel($details);
        }
        else if (isset($details->last4))
        {
            return __("%1 •••• %2", $methodName, $details->last4);
        }
        else if (isset($details->tax_id)) // Boleto
        {
            return __("%1 - %2", $methodName, $details->tax_id);
        }
        else
        {
            return ucfirst($type);
        }
    }

    public function formatPaymentMethods($methods)
    {
        $savedMethods = [];

        if ($this->dataHelper->getConfigData("payment/stripe_payments/cvc_code") == "new_saved_cards")
        {
            $cvc = 1;
        }
        else
        {
            $cvc = 0;
        }

        foreach ($methods as $type => $methodList)
        {
            $methodName = $this->getPaymentMethodName($type);

            switch ($type)
            {
                case "card":
                    foreach ($methodList as $method)
                    {
                        $details = $method->card;
                        $key = $details->fingerprint;

                        if (isset($savedMethods[$key]) && $savedMethods[$key]['created'] > $method->created)
                            continue;

                        $label = $this->getPaymentMethodLabel($method);

                        $savedMethods[$key] = [
                            "id" => $method->id,
                            "created" => $method->created,
                            "type" => $type,
                            "fingerprint" => $details->fingerprint,
                            "label" => $label,
                            "value" => $method->id,
                            "icon" => $this->getCardIcon($details->brand),
                            "cvc" => $cvc,
                            "brand" => $details->brand,
                            "exp_month" => $details->exp_month,
                            "exp_year" => $details->exp_year,
                        ];
                    }
                    break;
                case "link":
                    foreach ($methodList as $method)
                    {
                        $key = $method->id;
                        $label = $this->getPaymentMethodLabel($method);

                        $savedMethods[$key] = [
                            "id" => $method->id,
                            "created" => $method->created,
                            "type" => $type,
                            "label" => $label,
                            "value" => $method->id,
                            "icon" => $this->getPaymentMethodIcon($type),
                        ];
                    }
                    break;
                default:
                    foreach ($methodList as $method)
                    {
                        /** @var \Stripe\PaymentMethod $details */
                        $details = $method->{$type};
                        if (empty($details->fingerprint) || empty($details->last4))
                            continue;

                        $icon = $this->getPaymentMethodIcon($type);
                        if (!$icon)
                            $icon = $this->getPaymentMethodIcon("bank");

                        $key = $details->fingerprint;

                        if (isset($savedMethods[$key]) && $savedMethods[$key]['created'] > $method->created)
                            continue;

                        $label = $this->getPaymentMethodLabel($method);
                        if (empty($label))
                            continue;

                        $savedMethods[$key] = [
                            "id" => $method->id,
                            "created" => $method->created,
                            "type" => $type,
                            "fingerprint" => $details->fingerprint,
                            "label" => $label,
                            "value" => $method->id,
                            "icon" => $icon
                        ];
                    }
                    break;
            }
        }

        return $savedMethods;
    }

    protected function getViewFileUrl($fileId)
    {
        $areaCode = $this->state->getAreaCode();

        if ($areaCode === 'webapi_rest') {
            $this->appEmulation->startEnvironmentEmulation($this->storeManager->getStore()->getId(), \Magento\Framework\App\Area::AREA_FRONTEND, true);
        }

        try
        {
            $params = [
                '_secure' => $this->request->isSecure()
            ];

            $return = $this->assetRepo->getUrlWithParams($fileId, $params);//$this->assetRepo->getUrl($fileId);
        }
        catch (LocalizedException $e)
        {
            $return = null;
        }
        if ($areaCode === 'webapi_rest') {
            $this->appEmulation->stopEnvironmentEmulation();
        }
        return $return;
    }

    protected function getThemeModel()
    {
        if ($this->themeModel)
            return $this->themeModel;

        $themeId = $this->scopeConfig->getValue(
            \Magento\Framework\View\DesignInterface::XML_PATH_THEME_ID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );

        $this->themeModel = $this->themeProvider->getThemeById($themeId);

        return $this->themeModel;
    }

    public function insertPaymentMethods($paymentIntentResponse, $order, $array = false, $fromObserver = false)
    {
        $paymentMethodType = '';
        $cardData = [];
        if ($array) {
            if (isset($paymentIntentResponse['payment_method_details']['type'])
                && $paymentIntentResponse['payment_method_details']['type']) {
                $paymentMethod = $paymentIntentResponse['payment_method_details'];

                if ($paymentMethod['type'] === 'card') {
                    $cardData = ['card_type' => $paymentMethod['card']['brand'], 'card_data' => $paymentMethod['card']['last4']];

                    if (isset($paymentMethod['card']['wallet']['type']) && $paymentMethod['card']['wallet']['type']) {
                        $cardData['wallet'] = $paymentMethod['card']['wallet']['type'];
                    }
                }
                $paymentMethodType = $paymentMethod['type'];
            }
        } else {
            if (isset($paymentIntentResponse->charges->data[0]->payment_method_details->type)
                && $paymentIntentResponse->charges->data[0]->payment_method_details->type)
            {
                /** @var \Stripe\Charge $charge */
                $charge = $paymentIntentResponse->charges->data[0];
                $paymentMethod = $charge->payment_method_details;

                if ($paymentMethod->type === 'card') {
                    $cardData = ['card_type' => $paymentMethod->card->brand, 'card_data' => $paymentMethod->card->last4];

                    if (isset($paymentMethod->card->wallet->type) && $paymentMethod->card->wallet->type) {
                        $cardData['wallet'] = $paymentMethod->card->wallet->type;
                    }
                }
                $paymentMethodType = $paymentMethod->type;
            }
        }

        if ($fromObserver && $paymentMethodType) {
            $this->savePaymentMethod($order->getId(), $paymentMethodType, $this->json->serialize($cardData));
        } else {
            $extensionAttributes = $order->getExtensionAttributes();
            if ($extensionAttributes === null) {
                $extensionAttributes = $this->orderExtensionFactory->create();
            }
            if (method_exists($extensionAttributes, 'setPaymentMethodType') && method_exists($extensionAttributes, 'setPaymentMethodCardData'))
            {
                $extensionAttributes->setPaymentMethodType($paymentMethodType);
                $extensionAttributes->setPaymentMethodCardData($this->json->serialize($cardData));
                $order->setExtensionAttributes($extensionAttributes);
            }
        }
    }

    public function getIconFromPaymentType($type, $cardType = 'visa', $format = null)
    {
        if ($type === 'card') {
            $icon = $this->getCardIcon($cardType);
        } else {
            $icon = $this->getPaymentMethodIcon($type);
        }

        if (!$icon) {
            $icon = $this->getPaymentMethodIcon("bank");
        }

        if ($format)
            $icon = str_replace(".svg", ".$format", $icon);

        return $icon;
    }

    public function savePaymentMethod($orderId, $paymentMethodType, $cardData)
    {
        $modelClass = $this->stripePaymentMethodFactory->create();
        $this->resourceStripePaymentMethod->load($modelClass, $orderId, 'order_id');

        if (!$modelClass->getEntityId()) {
            $modelClass->setOrderId($orderId);
            $modelClass->setPaymentMethodType($paymentMethodType);
            $modelClass->setPaymentMethodCardData($cardData);
            $this->resourceStripePaymentMethod->save($modelClass);
        }
    }

    public function loadPaymentMethod($orderId)
    {
        $modelClass = $this->stripePaymentMethodFactory->create();
        $this->resourceStripePaymentMethod->load($modelClass, $orderId, 'order_id');
        return $modelClass;
    }

    public function getFilteredPaymentMethodTypes(): array
    {
        $options = [];
        $store = $this->storeManager->getStore();
        $currentStoreCode = strtolower($store->getCode());
        $currentCurrency = strtolower($store->getCurrentCurrency()->getCode());

        if (empty($currentStoreCode) || empty($currentCurrency))
            return $options;

        $paymentMethodsConfig = $this->scopeConfig->getValue('stripe_settings/payment_methods', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, 0);

        if (!is_array($paymentMethodsConfig))
            return $options;

        foreach ($paymentMethodsConfig as $storeCode => $currencies)
        {
            if ($currentStoreCode == strtolower($storeCode))
            {
                foreach ($currencies as $currency => $paymentMethods)
                {
                    if ($currentCurrency == strtolower($currency))
                    {
                        if (!empty($paymentMethods))
                        {
                            $options = explode(",", $paymentMethods);
                        }
                    }
                }
            }
        }

        return $this->filterMethodsByCartType($options);
    }

    public function filterMethodsByCartType($options)
    {
        $afterPayIndex = array_search("afterpay_clearpay", $options);
        if ($afterPayIndex !== false) {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId()) {
                return $options;
            }

            if ($quote->getIsVirtual()) {
                unset($options[$afterPayIndex]);
            }
        }

        return $options;
    }
}
