<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\CouldNotSaveException;
use StripeIntegration\Payments\Model;
use StripeIntegration\Payments\Model\PaymentMethod;
use StripeIntegration\Payments\Model\Config;
use Psr\Log\LoggerInterface;
use Magento\Framework\Validator\Exception;
use StripeIntegration\Payments\Helper\Logger;
use \Magento\Payment\Model\InfoInterface;

class Api
{
    private $helper;
    private $config;
    private $paymentIntent;
    private $quoteFactory;
    private $cache;
    private $paymentIntentCollectionFactory;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        Generic $helper,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Framework\App\CacheInterface $cache,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\CollectionFactory $paymentIntentCollectionFactory
    ) {
        $this->helper = $helper;
        $this->config = $config;
        $this->paymentIntent = $paymentIntent;
        $this->quoteFactory = $quoteFactory;
        $this->cache = $cache;
        $this->paymentIntentCollectionFactory = $paymentIntentCollectionFactory;
    }

    public function retrieveCharge($token)
    {
        if (empty($token))
            return null;

        if (strpos($token, 'pi_') === 0)
        {
            $pi = \Stripe\PaymentIntent::retrieve($token);

            if (empty($pi->charges->data[0]))
                return null;

            return $pi->charges->data[0];
        }
        else if (strpos($token, 'in_') === 0)
        {
            // Subscriptions save the invoice number instead
            $in = \Stripe\Invoice::retrieve(['id' => $token, 'expand' => ['charge']]);

            return $in->charge;
        }

        return \Stripe\Charge::retrieve($token);
    }

    public function reCreateCharge($payment, $baseAmount, \Stripe\Charge $originalCharge)
    {
        $order = $payment->getOrder();

        if (empty($originalCharge->payment_method) || empty($originalCharge->customer))
            throw new LocalizedException(__("The authorization has expired and the original payment method cannot be reused to re-create the payment."));

        $token = $originalCharge->payment_method;

        $fraud = false;

        $amount = $this->helper->convertBaseAmountToOrderAmount($baseAmount, $payment->getOrder(), $originalCharge->currency, 2);

        if ($amount > 0)
        {
            $quoteId = $order->getQuoteId();

            // We get here if an existing authorization has expired, in which case
            // we want to discard old Payment Intents and create a new one
            $this->paymentIntentCollectionFactory->create()->deleteForQuoteId($quoteId);

            $quote = $this->quoteFactory->create()->load($quoteId);

            $params = $this->paymentIntent->getParamsFrom($quote, $order, $token);
            $params['capture_method'] = \StripeIntegration\Payments\Model\PaymentIntent::CAPTURE_METHOD_AUTOMATIC;
            $params["customer"] = $originalCharge->customer;
            $params["amount"] = $this->helper->convertMagentoAmountToStripeAmount($amount, $originalCharge->currency);
            $params["currency"] = $originalCharge->currency;
            if (isset($params["payment_method_options"]))
                unset($params["payment_method_options"]);

            $paymentIntent = $this->config->getStripeClient()->paymentIntents->create($params);
            $confirmParams = $this->paymentIntent->getConfirmParams($order, $paymentIntent);
            $confirmParams = $this->filterPaymentMethodOptions($confirmParams);

            $key = "admin_captured_" . $paymentIntent->id;
            try
            {
                $this->cache->save($value = "1", $key, ["stripe_payments"], $lifetime = 60 * 60);
                $paymentIntent = $this->paymentIntent->confirm($paymentIntent, $confirmParams);
            }
            catch (\Exception $e)
            {
                $this->cache->remove($key);
                throw $e;
            }
            $this->paymentIntent->processSuccessfulOrder($order, $paymentIntent);
            return $paymentIntent;
        }

        return null;
    }

    public function createNewCharge(InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $token = $payment->getAdditionalInformation("token");
        $customerId = $payment->getAdditionalInformation("customer_stripe_id");
        $currency = $order->getOrderCurrencyCode();
        $amount = $this->helper->convertBaseAmountToOrderAmount($amount, $order, $currency, 2);

        if ($amount > 0)
        {
            $quoteId = $order->getQuoteId();
            $quote = $this->quoteFactory->create()->load($quoteId);

            $params = $this->paymentIntent->getParamsFrom($quote, $order, $token);
            $params['capture_method'] = \StripeIntegration\Payments\Model\PaymentIntent::CAPTURE_METHOD_AUTOMATIC;
            $params["customer"] = $customerId;
            $params["amount"] = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);
            $params["currency"] = $currency;
            if (isset($params["payment_method_options"]))
                unset($params["payment_method_options"]);

            $paymentIntent = $this->config->getStripeClient()->paymentIntents->create($params);
            $confirmParams = $this->paymentIntent->getConfirmParams($order, $paymentIntent);
            $confirmParams = $this->filterPaymentMethodOptions($confirmParams);

            $key = "admin_captured_" . $paymentIntent->id;
            try
            {
                $this->cache->save($value = "1", $key, ["stripe_payments"], $lifetime = 60 * 60);
                $paymentIntent = $this->paymentIntent->confirm($paymentIntent, $confirmParams);
            }
            catch (\Exception $e)
            {
                $this->cache->remove($key);
                throw $e;
            }
            $this->paymentIntent->processSuccessfulOrder($order, $paymentIntent);
            return $paymentIntent;
        }

        return null;
    }

    protected function filterPaymentMethodOptions($params)
    {
        if (isset($params['payment_method_options']))
        {
            // We don't want to authorize only and we don't want to setup future usage, but we want to keep the moto parameter
            $moto = isset($params['payment_method_options']['card']['moto']) ? $params['payment_method_options']['card']['moto'] : false;
            unset($params["payment_method_options"]);
            if ($moto)
                $params['payment_method_options']['card']['moto'] = $moto;
        }

        return $params;
    }
}
