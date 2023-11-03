<?php

namespace StripeIntegration\Payments\Test\Integration\Helper;

class Checkout
{
    protected $objectManager = null;
    protected $tests = null;
    private $address;
    private $helper;
    private $stripeConfig;

    public function __construct($tests)
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->address = $this->objectManager->get(\StripeIntegration\Payments\Test\Integration\Helper\Address::class);
        $this->tests = $tests;
    }

    public function retrieveSession($order, $cart = "")
    {
        $checkoutSessionId = $order->getPayment()->getAdditionalInformation('checkout_session_id');
        $currency = $order->getOrderCurrencyCode();
        $amount = $this->helper->convertMagentoAmountToStripeAmount($order->getGrandTotal(), $currency);

        $this->tests->assertNotEmpty($checkoutSessionId);
        $session = $this->stripeConfig->getStripeClient()->checkout->sessions->retrieve($checkoutSessionId);

        // When there are trial subscriptions in the cart, the session amount_total will not match the Magento order
        if (stripos($cart, "trial") === false && stripos($cart, "OrderOnly") === false && !$order->getHasStartDate())
            $this->tests->assertEquals($amount, $session->amount_total);

        return $session;
    }

    public function confirm($session, $order, $paymentMethod = "SuccessCard", $billingAddress = "NewYork")
    {
        // Build confirmation params
        if ($paymentMethod == "sofort_success")
        {
            $params = [
                'eid' => 'NA',
                'expected_amount' => $session->amount_total,
                'return_url' => $this->helper->getUrl('stripe/payment/index'),
                'payment_method' => "pm_sofort_generatedSepaDebitIntentsSucceedGermany",
                'expected_payment_method_type' => "sofort"
            ];
        }
        else
        {
            $paymentMethod = $this->createPaymentMethod($paymentMethod, $billingAddress);
            $params = $this->getParamsForPaymentMethod($paymentMethod, $session);
        }

        // Confirm the payment
        return $this->stripeConfig->getStripeClient()->request('post', "/v1/payment_pages/{$session->id}/confirm", $params, $opts = null);
    }

    public function authenticate($paymentIntent, $adapter, $success = true)
    {
        $this->tests->assertNotEmpty($paymentIntent);

        if (empty($paymentIntent->next_action))
            return true; // Authentication is not needed for this payment method

        if (isset($paymentIntent->next_action->use_stripe_sdk->stripe_js))
        {
            $endpoint = $paymentIntent->next_action->use_stripe_sdk->stripe_js;
        }
        else if (isset($paymentIntent->next_action->redirect_to_url->url))
        {
            $url = $paymentIntent->next_action->redirect_to_url->url;

            // Get the PM token
            preg_match('/authenticate\/([^\?]+)\?/', $url, $matches);
            if (empty($matches[1]))
            {
                // The URL will be https://pm-redirects.stripe.com/authorize/acct_xxxxx/pa_nonce_xxxxx
                // The Authorize button will be https://pm-redirects.stripe.com/return/acct_xxxxx/pa_nonce_xxxxx?success=true
                throw new \Exception("URL $url does not include an /authenticate endpoint");
            }
            else
            {
                $this->tests->assertNotEmpty($matches[1]);
                $token = $matches[1];

                // Get the client secret
                preg_match('/client_secret=(.+)/', $url, $matches);
                $this->tests->assertNotEmpty($matches[1]);
                $clientSecret = $matches[1];

                if (strpos($adapter, "Card") !== false) // SuccessCard, ElevatedRiskCard etc
                    $adapter = "card";

                $endpoint = "https://hooks.stripe.com/adapter/$adapter/redirect/complete/$token/$clientSecret?success=true";
            }
        }
        else
        {
            throw new \Exception("Cannot authenticate payment intent because it has no redirect url");
        }

        if ($success)
        {
            // Authenticate
            $result = file_get_contents($endpoint);
            return $result;
        }
        else
            throw new \Exception("Authentication failure is not supported");
    }

    public function createPaymentMethod($type, $billingAddress)
    {
        $stripe = $this->stripeConfig->getStripeClient();
        $params = [
            "billing_details" => $this->address->getStripeFormat($billingAddress),
            "type" => strtolower($type)
        ];

        switch ($type)
        {
            case "SuccessCard":
                $params['type'] = 'card';
            case "card":
                $params['card'] = [
                    'number' => '4242424242424242',
                    'exp_month' => 7,
                    'exp_year' => date("Y", time()) + 1,
                    'cvc' => '314',
                ];
                break;
            case "sofort":
                $params["sofort"] = [
                    'country' => $params["billing_details"]["address"]["country"]
                ];
                break;
            case "sepa_debit":
                $params["sepa_debit"] = [
                    'iban' => "DE89370400440532013000"
                ];
                break;
            case "us_bank_account":
                $params["us_bank_account"] = [
                    'account_holder_type' => "individual",
                    'account_number' => "0123456789",
                    'account_type' => "savings",
                    'routing_number' => "021000021"
                ];
                break;
            case "bacs_debit":
                $params["bacs_debit"] = [
                    'account_number' => "00012345",
                    'sort_code' => '108800'
                ];
                break;
            case "au_becs_debit":
                $params["au_becs_debit"] = [
                    'account_number' => "000123456",
                    'bsb_number' => '000000'
                ];
                break;
            case "klarna":
                $params["klarna"] = [];
                break;
            default:
                break;
        }

        return $stripe->paymentMethods->create($params);
    }

    public function getParamsForPaymentMethod($paymentMethod, $session)
    {
        $params = [
            'eid' => 'NA',
            'payment_method' => $paymentMethod->id,
            'expected_amount' => $session->amount_total,
            'expected_payment_method_type' => $paymentMethod->type,
            'return_url' => $this->helper->getUrl('stripe/payment/index')
        ];

        return $params;
    }
}
