<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;

class PaymentIntent
{
    /**
     * @var \StripeIntegration\Payments\Model\Config
     */
    protected $config;

    const ONLINE_ACTIONS = [
        'three_d_secure_redirect',
        'use_stripe_sdk',
        'redirect_to_url'
    ];

    const CANCELABLE_STATUSES = [
        'requires_payment_method',
        'requires_capture',
        'requires_confirmation',
        'requires_action',
        'requires_source',
        'processing'
    ];

    private $remoteAddress;
    private $httpHeader;

    public function __construct(
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        \Magento\Framework\HTTP\Header $httpHeader,
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $this->remoteAddress = $remoteAddress;
        $this->httpHeader = $httpHeader;
        $this->config = $config;
    }

    public function isSuccessful($paymentIntent)
    {
        if (in_array($paymentIntent->status, ['succeeded', 'requires_capture', 'processing']))
        {
            return true;
        }

        return false;
    }

    public function requiresOfflineAction($paymentIntent)
    {
        if ($paymentIntent->status == "requires_action"
            && !empty($paymentIntent->next_action->type)
            && !in_array($paymentIntent->next_action->type, self::ONLINE_ACTIONS)
        )
        {
            return true;
        }

        return false;
    }

    public function canCancel($paymentIntent)
    {
        return in_array($paymentIntent->status, self::CANCELABLE_STATUSES)
            && empty($paymentIntent->invoice); // Subscription PIs cannot be canceled
    }

    public function canConfirm($paymentIntent)
    {
        return $paymentIntent->status == "requires_confirmation";
    }

    public function isSetupIntent($id)
    {
        if (!empty($id) && strpos($id, "seti_") === 0)
            return true;

        return false;
    }

    protected function hasFinalizedInvoice($paymentIntent)
    {
        if (empty($paymentIntent->invoice))
            return false;

        if (is_string($paymentIntent->invoice))
            $invoice = \Stripe\Invoice::retrieve($paymentIntent->invoice);
        else
            $invoice = $paymentIntent->invoice;

        if ($invoice->status == 'open')
            return false;

        return true;
    }

    public function getUpdateableParams($params, $paymentIntent = null)
    {
        if (($paymentIntent && ($this->isSuccessful($paymentIntent) || $this->requiresOfflineAction($paymentIntent)) || $this->isSetupIntent($paymentIntent->id))
            || $this->hasFinalizedInvoice($paymentIntent))
        {
            $updateableParams = [
                "description",
                "metadata"
            ];
        }
        else
        {
            $updateableParams = [
                "amount",
                "description",
                "metadata",
                "setup_future_usage",
                "shipping" // Required by certain methods like AfterPay/Clearpay
            ];

            if (empty($paymentIntent->invoice))
                $updateableParams[] = "currency";

            // If the Stripe account is not gated, adding these params will crash the PaymentIntent::update() call
            if ($this->config->isLevel3DataEnabled())
                $updateableParams[] = "level3";

            // The payment method cannot be unset, so we only update it if it is set
            if (!empty($params["payment_method"]))
            {
                $updateableParams[] = "payment_method";
            }

            // We can only set the customer, we cannot change it
            if (!empty($params["customer"]) && empty($paymentIntent->customer))
            {
                $updateableParams[] = "customer";
            }
        }

        $nonEmptyParams = [];

        foreach ($updateableParams as $paramName)
        {
            if (!empty($params[$paramName]))
                $nonEmptyParams[] = $paramName;
        }

        return $nonEmptyParams;
    }

    public function getFilteredParamsForUpdate($params, $paymentIntent = null)
    {
        $newParams = [];

        foreach ($this->getUpdateableParams($params, $paymentIntent) as $key)
        {
            if (isset($params[$key]))
                $newParams[$key] = $params[$key];
            else
                $newParams[$key] = null; // Unsets it through the API
        }

        return $newParams;
    }

    public function getMandateData($paymentMethod, $intent): array
    {
        $params = [];
        $remoteAddress = $this->remoteAddress->getRemoteAddress();
        $userAgent = $this->httpHeader->getHttpUserAgent();
        $unsupportedMethods = ['afterpay_clearpay', 'paypal', 'blik'];

        if (!$remoteAddress || !$userAgent || empty($paymentMethod->type) || in_array($paymentMethod->type, $unsupportedMethods))
        {
            return [];
        }

        $params['mandate_data']['customer_acceptance'] = [
            "type" => "online",
            "online" => [
                "ip_address" => $remoteAddress,
                "user_agent" => $userAgent,
            ]
        ];

        return $params;
    }

    public function getWechatClient()
    {
        $userAgent = $this->httpHeader->getHttpUserAgent();

        if(strpos($userAgent, 'Android') !== false) {
            return 'android';
        }

        if(strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false || strpos($userAgent, 'iPod') !== false) {
            return 'ios';
        }

        return 'web';
    }
}
