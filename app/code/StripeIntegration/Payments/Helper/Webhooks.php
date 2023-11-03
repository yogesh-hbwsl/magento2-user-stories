<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\WebhookException;
use StripeIntegration\Payments\Exception\OrderNotFoundException;
use StripeIntegration\Payments\Exception\RetryLaterException;
use StripeIntegration\Payments\Exception\SubscriptionUpdatedException;
use StripeIntegration\Payments\Exception\MissingOrderException;

class Webhooks
{
    protected $output = null;
    private $debug = false;
    private $webhooksLogger;
    private $eventManager;
    private $invoiceFactory;
    private $paymentElementFactory;
    private $config;
    private $creditmemoFactory;
    private $creditmemoService;
    private $urlInterface;
    private $webhookCollection;
    private $webhookEventCollectionFactory;
    private $paymentIntentFactory;
    private $checkoutSessionFactory;
    private $webhookEventFactory;
    private $emailHelper;
    private $response;
    private $request;
    private $subscriptionsHelper;
    private $helper;
    private $cache;
    private $creditmemoHelper;
    private $orderCommentSender;

    public function __construct(
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\App\Response\Http $response,
        \StripeIntegration\Payments\Logger\WebhooksLogger $webhooksLogger,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Model\InvoiceFactory $invoiceFactory,
        \StripeIntegration\Payments\Model\PaymentElementFactory $paymentElementFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Magento\Framework\UrlInterface $urlInterface,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook\Collection $webhookCollection,
        \StripeIntegration\Payments\Model\ResourceModel\WebhookEvent\CollectionFactory $webhookEventCollectionFactory,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentFactory,
        \StripeIntegration\Payments\Model\CheckoutSessionFactory $checkoutSessionFactory,
        \StripeIntegration\Payments\Model\WebhookEventFactory $webhookEventFactory,
        \StripeIntegration\Payments\Helper\Email $emailHelper,
        \StripeIntegration\Payments\Helper\Creditmemo $creditmemoHelper,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender $orderCommentSender
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->webhooksLogger = $webhooksLogger;
        $this->helper = $helper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->eventManager = $eventManager;
        $this->invoiceFactory = $invoiceFactory;
        $this->paymentElementFactory = $paymentElementFactory;
        $this->config = $config;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->urlInterface = $urlInterface;
        $this->webhookCollection = $webhookCollection;
        $this->webhookEventCollectionFactory = $webhookEventCollectionFactory;
        $this->paymentIntentFactory = $paymentIntentFactory;
        $this->checkoutSessionFactory = $checkoutSessionFactory;
        $this->webhookEventFactory = $webhookEventFactory;
        $this->emailHelper = $emailHelper;
        $this->creditmemoHelper = $creditmemoHelper;
        $this->cache = $cache;
        $this->orderCommentSender = $orderCommentSender;
    }

    public function setOutput(\Symfony\Component\Console\Output\OutputInterface $output)
    {
        $this->output = $output;
    }

    public function dispatchEvent($stdEvent = null, $processMoreThanOnce = false)
    {
        $webhookEventModel = null;

        try
        {
            if (!$stdEvent)
            {
                if ($this->request->getMethod() == 'GET')
                    throw new WebhookException("Your webhooks endpoint is accessible from your location.", 200);

                // Retrieve the request's body and parse it as JSON
                $body = $this->request->getContent();
                $event = json_decode($body, true);
                $stdEvent = json_decode($body);

                $eventType = $this->getEventType($event);

                if (isset($event['id']))
                    $eventId = " (" . $event['id'] . ")";
                else
                    $eventId = "";

                $this->log("Received $eventType" . $eventId);

                $this->verifyWebhookSignature();
            }
            else
            {
                $event = json_decode(json_encode($stdEvent), true);

                $eventType = $this->getEventType($event);

                if (isset($event['id']))
                    $eventId = " (" . $event['id'] . ")";
                else
                    $eventId = "";

                $this->log("Received $eventType" . $eventId);
            }

            if (!empty($this->request->getParam('dev')))
            {
                $processMoreThanOnce = true;
            }

            $webhookModel = $this->webhookCollection->findFromRequest($this->request);
            if ($webhookModel && $webhookModel->getId())
            {
                $webhookModel->pong()->save();
            }

            $webhookEventModel = $this->webhookEventFactory->create()->fromStripeObject($event, $processMoreThanOnce);

            if ($event['type'] == "product.created")
            {
                $this->onProductCreated($event, $stdEvent);
                $webhookEventModel->markAsProcessed();
                $this->log("200 OK" . $eventId);
                return;
            }

            $this->response->setStatusCode(500);
            $this->eventManager->dispatch($eventType, array(
                    'arrEvent' => $event,
                    'stdEvent' => $stdEvent,
                    'object' => $event['data']['object'],
                    'paymentMethod' => $this->getPaymentMethodFrom($event)
                ));
            $this->response->setStatusCode(200);

            $webhookEventModel->markAsProcessed();
            $this->log("200 OK" . $eventId);
        }
        catch (OrderNotFoundException $e)
        {
            $statusCode = 200;

            if ($webhookEventModel)
            {
                $webhookEventModel->refresh()->setLastErrorFromException($e, $e->statusCode);
            }

            $this->response->setStatusCode($statusCode);
            $this->error(__("Event queued for processing"), $statusCode, true);
        }
        catch (RetryLaterException $e)
        {
            $statusCode = 409;
            $webhookEventModel->delete();
            $this->response->setStatusCode($statusCode);
            $this->error(__($e->getMessage()), $statusCode, true);
        }
        catch (WebhookException $e)
        {
            if (!empty($e->statusCode))
                $this->response->setStatusCode($e->statusCode);
            else
                $this->response->setStatusCode(202);

            $statusCode = $this->response->getStatusCode();

            $this->error($e->getMessage(), $statusCode, true);

            if ($webhookEventModel)
            {
                $webhookEventModel->refresh()->setLastErrorFromException($e, $statusCode);
            }
        }
        catch (\Exception $e)
        {
            $statusCode = 500;
            $this->response->setStatusCode($statusCode);

            $this->log($e->getMessage());
            $this->log($e->getTraceAsString());
            $this->error($e->getMessage(), $statusCode);

            if ($webhookEventModel)
            {
                $webhookEventModel->refresh()->setLastErrorFromException($e, $statusCode);
            }
        }
    }

    protected function getEventType(array $event)
    {
        if (empty($event['type']))
            return "payload with no event type";

        $eventType = "stripe_payments_webhook_" . str_replace(".", "_", $event['type']);
        return $eventType;
    }

    protected function getPaymentMethodFrom($event)
    {
        if (isset($event['data']['object']['type']))
            $paymentMethod = $event['data']['object']['type'];
        else if (isset($event['data']['object']['payment_method_types']))
            $paymentMethod = implode("_", $event['data']['object']['payment_method_types']);
        else if (isset($event['data']['object']['payment_method_details']))
            $paymentMethod = $event['data']['object']['payment_method_details']['type'];
        else
            $paymentMethod = '';

        return $paymentMethod;
    }

    public function onProductCreated($event, $stdEvent)
    {
        if ($event['data']['object']['name'] == "Webhook Configuration")
        {
            $this->eventManager->dispatch("automatic_webhook_configuration", array('event' => $stdEvent));
        }
        else if ($event['data']['object']['name'] == "Webhook Ping")
        {
            $this->webhookCollection->pong($event['data']['object']['metadata']['pk']);
        }
    }

    public function error($msg, $status, $displayError = false)
    {
        if ($this->output)
        {
            if ($status)
            {
                if ($status < 300)
                    return $this->output->writeln("$status $msg");
                else
                    return $this->output->writeln("<error>$status $msg</error>");
            }
            else
                return $this->output->writeln("<error>$msg</error>");
        }

        if ($status && $status > 0)
            $this->log("$status $msg");
        else
            $this->log("No status: $msg");

        if (!$displayError && !$this->debug)
            $msg = "An error has occurred. Please check var/log/stripe_payments_webhooks.log for more details.";

        $this->response
            ->setHeader('Content-Type', 'text/plain', $overwriteExisting = true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setContent($msg);
    }

    public function log($msg)
    {
        if ($this->output)
            $this->output->writeln($msg);
        // Magento 2.0.0 - 2.4.3
        else if (method_exists($this->webhooksLogger, 'addInfo'))
            $this->webhooksLogger->addInfo($msg);
        // Magento 2.4.4+
        else
            $this->webhooksLogger->info($msg);
    }

    public function verifyWebhookSignature()
    {
        $signingSecrets = $this->config->getWebhooksSigningSecrets();
        if (empty($signingSecrets))
            return;

        $success = false;
        $errors = [];
        $count = 1;
        foreach ($signingSecrets as $signingSecret)
        {
            try
            {
                if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE']))
                    throw new \Stripe\Exception\SignatureVerificationException("Webhook signature could not be found in the request headers.", 400);

                // throws SignatureVerificationException
                $event = \Stripe\Webhook::constructEvent($this->request->getContent(), $_SERVER['HTTP_STRIPE_SIGNATURE'], $signingSecret);

                $success = true;
            }
            catch(\UnexpectedValueException $e)
            {
                $key = hash('md2', $e->getMessage());
                $errors[$key] = "#" . $count++ . " " . $e->getMessage();

                throw new WebhookException("Invalid webhook payload.", 400);
            }
            catch(\Stripe\Exception\SignatureVerificationException $e)
            {
                $key = hash('md2', $e->getMessage());
                $errors[$key] = "#" . $count++ . " " . $e->getMessage();
            }
        }

        if (!$success)
        {
            $this->log("Webhook origin check failed with " . count($errors) . " errors:\n" . implode("\n", $errors));
            throw new WebhookException("Webhook origin check failed.", 400);
        }
    }

    // Does not throw an exception
    public function getOrderIdFromObject(array $object, $includeMultishipping = false)
    {
        // For most payment methods, the order ID is here
        if (!empty($object['metadata']['Order #']))
            return $object['metadata']['Order #'];

        // Multishipping cases
        if (!empty($object['metadata']['Multishipping']) && !empty($object['metadata']['Orders']))
        {
            $data = $object['metadata']['Orders'];
            $data = str_replace(" ", "", $data);
            $data = str_replace("#", "", $data);
            return explode(",", $data);
        }

        if ($object['object'] == 'invoice')
        {
            // For invoices created from the Magento admin
            $entry = $this->invoiceFactory->create()->load($object['id'], 'invoice_id');
            if ($entry->getOrderIncrementId())
            {
                return $entry->getOrderIncrementId();
            }

            if (!empty($object["subscription"]))
            {
                // If the subscription was updated with no proration, no new order exists. The quote will be used to create the new order
                $subscriptionModel = $this->subscriptionsHelper->loadSubscriptionModelBySubscriptionId($object['subscription']);
                if ($subscriptionModel && $subscriptionModel->getReorderFromQuoteId())
                {
                    throw new SubscriptionUpdatedException($subscriptionModel->getReorderFromQuoteId());
                }

                // Subscriptions that were just bought with PaymentElement do not have metadata yet
                $paymentElement = $this->paymentElementFactory->create()->load($object['subscription'], 'subscription_id');
                if ($paymentElement->getOrderIncrementId())
                {
                    return $paymentElement->getOrderIncrementId();
                }

                // This may be a more appropriate entry to check than PaymentElement, but it needs more testing before we swap them.
                if ($subscriptionModel && $subscriptionModel->getOrderIncrementId())
                {
                    return $subscriptionModel->getOrderIncrementId();
                }
            }

            // Subscriptions bought using Stripe Checkout
            foreach ($object['lines']['data'] as $lineItem)
            {
                if ($lineItem['type'] == "subscription" && !empty($lineItem['metadata']['Order #']))
                {
                    return $lineItem['metadata']['Order #'];
                }
            }
        }
        else if ($object['object'] == 'setup_intent')
        {
            $paymentElement = $this->paymentElementFactory->create()->load($object['id'], 'setup_intent_id');
            if ($paymentElement->getOrderIncrementId())
            {
                return $paymentElement->getOrderIncrementId();
            }
        }
        // If the merchant refunds a charge of a recurring subscription order from the Stripe dashboard, we need to drill down to the parent subscription
        else if ($object['object'] == 'charge' && !empty($object['invoice']) && !empty($object['customer']) && $this->config->reInitStripeFromCustomerId($object['customer']))
        {
            $stripe = $this->config->getStripeClient();

            $charge = $stripe->charges->retrieve($object['id'], ['expand' => ['payment_intent']]);
            if (!empty($charge->payment_intent->metadata->{"Order #"}))
            {
                return $charge->payment_intent->metadata->{"Order #"};
            }

            $count = 2;
            $invoice = null;
            do
            {
                try
                {
                    $invoice = $stripe->invoices->retrieve($object['invoice'], ['expand' => ['subscription']]);
                }
                catch (\Exception $e)
                {
                    // Sometimes we get: This object cannot be accessed right now because another API request or Stripe process is currently accessing it.
                    sleep(1);
                }
                $count--;
            }
            while ($count > 0 && empty($invoice));

            if (!empty($invoice->subscription->metadata->{"Order #"}))
                return $invoice->subscription->metadata->{"Order #"};
        }
        else if ($object['object'] == "checkout.session")
        {
            $checkoutSessionModel = $this->checkoutSessionFactory->create()->load($object["id"], 'checkout_session_id');
            if ($checkoutSessionModel->getOrderIncrementId())
                return $checkoutSessionModel->getOrderIncrementId();
        }
        // Triggered via stripe_payments_webhook_review_closed
        else if (!empty($object['payment_intent']))
        {
            if ($includeMultishipping)
            {
                // Search for all orders which have this payment intent as a transaction ID
                $orders = $this->helper->getOrdersByTransactionId($object['payment_intent']);
                if (!empty($orders))
                {
                    $ids = [];
                    foreach ($orders as $order)
                        $ids[$order->getIncrementId()] = $order->getIncrementId();

                    return $ids;
                }
            }
            else
            {
                $paymentIntent = $this->paymentIntentFactory->create()->load($object['payment_intent'], 'pi_id');
                $orderId = $paymentIntent->getOrderIncrementId();
                if ($orderId)
                    return $orderId;
            }
        }

        return null;
    }

    public function getPaymentMethod(array $object)
    {
        // Most APMs
        if (!empty($object["type"]))
            return $object["type"];

        return null;
    }

    public function loadOrderFromInvoiceId($invoiceId, $event)
    {
        $entry = $this->invoiceFactory->create()->load($invoiceId, 'invoice_id');
        if (!$entry->getOrderIncrementId())
            throw new OrderNotFoundException("We could not find the order for the invoice associated with this charge.", 202);

        return $this->loadWebhookOrderByIncrementId($entry->getOrderIncrementId(), $event);
    }

    protected function recordOrderIdAgainstEvent($eventId, $orderIncrementId)
    {
        $webhookEventModel = $this->webhookEventFactory->create()->load($eventId, 'event_id');

        if (!$webhookEventModel->getId())
        {
            return;
        }

        if (is_array($orderIncrementId))
        {
            $webhookEventModel->setOrderIncrementId(implode(",", $orderIncrementId))->save();
        }
        else if (is_string($orderIncrementId))
        {
            $webhookEventModel->setOrderIncrementId($orderIncrementId)->save();
        }
    }

    public function loadOrderFromEvent(?array $event, $includeMultishipping = false)
    {
        if (!is_array($event) || empty($event['id']))
            throw new WebhookException(__("Received invalid request payload."), 400);

        $orderId = $this->getOrderIdFromObject($event['data']['object'], $includeMultishipping);

        if (empty($orderId))
            throw new MissingOrderException(__("Received %1 webhook but there was no associated Order #", $event['type']), 202);

        $this->recordOrderIdAgainstEvent($event['id'], $orderId);

        if (is_array($orderId))
        {
            if (!$includeMultishipping)
                throw new WebhookException(__("This is a multi-shipping event that has not been implemented; ignoring."), 202);

            $orders = [];
            $orderIds = $orderId;
            foreach ($orderIds as $orderId)
                $orders[] = $this->loadWebhookOrderByIncrementId($orderId, $event);

            return $orders;
        }
        else
        {
            if ($includeMultishipping)
            {
                return [ $this->loadWebhookOrderByIncrementId($orderId, $event) ];
            }
            else
            {
                return $this->loadWebhookOrderByIncrementId($orderId, $event);
            }
        }
    }

    public function initStripeFrom($order, $event)
    {
        $paymentMethodCode = $order->getPayment()->getMethod();
        $orderId = $order->getIncrementId();
        if (strpos($paymentMethodCode, "stripe") !== 0)
            throw new WebhookException("Order #$orderId was not placed using Stripe", 202);

        // For multi-stripe account configurations, load the correct Stripe API key from the correct store view
        if (isset($event['data']['object']['livemode']))
            $mode = ($event['data']['object']['livemode'] ? "live" : "test");
        else
            $mode = null;
        $this->config->reInitStripe($order->getStoreId(), $order->getOrderCurrencyCode(), $mode);
        $this->webhookCollection->pong($this->config->getPublishableKey($mode));
    }

    protected function loadWebhookOrderByIncrementId($orderId, $event)
    {
        if (empty($orderId))
            throw new WebhookException(__("Ignoring %1 webhook event with no associated order ID.", $event['type']), 202);

        $order = $this->helper->loadOrderByIncrementId($orderId);

        if (empty($order) || empty($order->getId()))
            throw new OrderNotFoundException(__("Received %1 webhook with Order #%2 but could not find the order in Magento.", $event['type'], $orderId), 202);

        $this->initStripeFrom($order, $event);

        return $order;
    }

    // Called after a source.chargable event
    public function charge($order, $object, $addTransaction = true, $sendNewOrderEmail = true)
    {
        $orderId = $order->getIncrementId();

        $payment = $order->getPayment();
        if (!$payment)
            throw new WebhookException("Could not load payment method for order #$orderId");

        $orderSourceId = $payment->getAdditionalInformation('source_id');
        $webhookSourceId = $object['id'];
        if ($orderSourceId != $webhookSourceId)
            throw new WebhookException("Received source.chargeable webhook for order #$orderId but the source ID on the webhook $webhookSourceId was different than the one on the order $orderSourceId");

        $stripeParams = $this->config->getStripeParamsFrom($order);

        // Reusable sources may not have an amount set
        if (empty($object['amount']))
        {
            $amount = $stripeParams['amount'];
        }
        else
        {
            $amount = $object['amount'];
        }

        $params = array(
            "amount" => $amount,
            "currency" => $object['currency'],
            "source" => $webhookSourceId,
            "description" => $stripeParams['description'],
            "metadata" => $stripeParams['metadata']
        );

        // For reusable sources, we will always need a customer ID
        $customerStripeId = $payment->getAdditionalInformation('customer_stripe_id');
        if (!empty($customerStripeId))
            $params["customer"] = $customerStripeId;

        try
        {
            $charge = \Stripe\Charge::create($params);

            $payment->setTransactionId($charge->id);
            $payment->setLastTransId($charge->id);
            $payment->setIsTransactionClosed(0);

            // Log additional info about the payment
            $info = $this->helper->getClearSourceInfo($object[$object['type']]);
            $payment->setAdditionalInformation('source_info', json_encode($info));
            $payment->save();

            if ($addTransaction)
            {
                if (!$charge->captured)
                    $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH;
                else
                    $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
                //Transaction::TYPE_PAYMENT

                $transaction = $payment->addTransaction($transactionType, null, false);
                $transaction->save();
            }

            if ($charge->status == 'succeeded')
            {
                if ($charge->captured == false)
                    // $invoice = $this->helper->invoicePendingOrder($order, \Magento\Sales\Model\Order\Invoice::NOT_CAPTURE, $charge->id);
                    return;
                else
                    $invoice = $this->helper->invoiceOrder($order, $charge->id);

                if ($sendNewOrderEmail)
                    $this->helper->sendNewOrderEmailFor($order, true);
            }
            // SEPA, SOFORT and other asynchronous methods will be pending
            else if ($charge->status == 'pending')
            {
                $invoice = $this->helper->invoicePendingOrder($order, $charge->id);

                if ($sendNewOrderEmail)
                    $this->helper->sendNewOrderEmailFor($order, true);
            }
            else
            {
                // In theory we should never have failed charges because they would throw an exception
                $comment = "Authorization failed. Transaction ID: {$charge->id}. Charge status: {$charge->status}";
                $order->addStatusHistoryComment($comment);
                $this->helper->saveOrder($order);
            }

            return $charge;
        }
        catch (\Stripe\Exception\CardException $e)
        {
            $comment = "Order could not be charged because of a card error: " . $e->getMessage();
            $order->addStatusHistoryComment($comment);
            $this->helper->saveOrder($order);
            $this->log($e->getMessage());
            throw new WebhookException($e->getMessage(), 202);
        }
        catch (\Exception $e)
        {
            $comment = "Order could not be charged because of server side error: " . $e->getMessage();
            $order->addStatusHistoryComment($comment);
            $this->helper->saveOrder($order);
            $this->log($e->getMessage());
            throw new WebhookException($e->getMessage(), 202);
        }
    }

    public function refundOfflineOrCancel($order)
    {
        $invoices = $order->getInvoiceCollection();
        foreach ($invoices as $invoice)
        {
            if ($invoice->canCancel())
            {
                $invoice->cancel();
                $this->helper->saveInvoice($invoice);
            }
        }

        if ($order->canCreditmemo())
        {
            foreach($order->getInvoiceCollection() as $invoice)
            {
                if ($invoice->getIsPaid())
                {
                    $creditmemo = $this->creditmemoFactory->createByOrder($order);
                    $creditmemo->setInvoice($invoice);
                    $this->creditmemoService->refund($creditmemo, true);
                }
            }
        }

        if ($order->canCancel())
        {
            $order->cancel();
        }

        $this->helper->saveOrder($order);
    }

    public function removeEndpoint()
    {
        $url = $this->urlInterface->getCurrentUrl();
        $endpoints = \Stripe\WebhookEndpoint::all();
        foreach ($endpoints as $endpoint)
        {
            if (strpos($url, $endpoint->url) === 0)
            {
                $endpoint = \Stripe\WebhookEndpoint::retrieve($endpoint->id);
                $endpoint->delete();
            }
        }
    }

    public function sendRecurringOrderFailedEmail($eventArray, $exception)
    {
        try
        {
            $generalName = $this->emailHelper->getName('general');
            $generalEmail = $this->emailHelper->getEmail('general');

            if ($eventArray['livemode'])
                $mode = '';
            else
                $mode = 'test/';

            $object = $eventArray['data']['object'];

            $templateVars = [
                'paymentLink' => "https://dashboard.stripe.com/{$mode}payments/" . $object["payment_intent"],
                'subscriptionLink' => "https://dashboard.stripe.com/{$mode}subscriptions/" . $object["subscription"],
                'customerLink' => "https://dashboard.stripe.com/{$mode}customers/" . $object["customer"],
                'errorMessage' => $exception->getMessage(),
                'stackTrace' => $exception->getTraceAsString(),
                'eventLink' => "https://dashboard.stripe.com/{$mode}events/" . $eventArray["id"]
            ];

            $sent = $this->emailHelper->send('stripe_failed_recurring_order', $generalName, $generalEmail, $generalName, $generalEmail, $templateVars);

            if (!$sent)
            {
                $this->helper->logError($exception->getMessage(), $exception->getTraceAsString());
            }
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            $this->helper->logError($exception->getMessage(), $exception->getTraceAsString());
        }
    }

    public function detectRaceCondition($orderIncrementId, $clashingEventTypes)
    {
        $count = $this->webhookEventCollectionFactory->create()->getProcessingEventsCount($orderIncrementId, $clashingEventTypes);

        if ($count > 0)
        {
            throw new RetryLaterException("Race condition detected. Another webhook event is mutating data on the same order. Try again shortly.");
        }
    }

    public function setPaymentDescriptionAfterSubscriptionUpdate($order, \Stripe\Invoice $invoice)
    {
        if (empty($invoice->billing_reason))
            return;

        if ($invoice->billing_reason != "subscription_update")
            return;

        if (empty($invoice->payment_intent->id))
            return;

        $paymentIntent = $invoice->payment_intent;
        if (!empty($paymentIntent->description) && $paymentIntent->description != "Subscription update")
            return;

        $description = $this->helper->getOrderDescription($order);

        try
        {
            $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, [
                'description' => $description
            ]);
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
        }
    }

    public function setSubscriptionStatusWhenCustomerUpdate($subscriptionId, $subscriptionStatus)
    {
        $subscriptionModel = $this->subscriptionsHelper->loadSubscriptionModelBySubscriptionId($subscriptionId);
        if ($subscriptionModel)
        {
            $subscriptionModel->setStatus($subscriptionStatus);
            $subscriptionModel->save();
        }
    }

    public function getValidWebhookUrl($store)
    {
        try
        {
            $url = $this->getWebhookUrl($store);
            if ($this->isValidUrl($url))
            {
                return $url;
            }
        }
        catch (\Exception $e)
        {
            return null;
        }

        return null;
    }

    public function getWebhookUrl($store)
    {
        $url = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true);

        if (empty($url))
        {
            return null;
        }

        $url = filter_var($url, FILTER_SANITIZE_URL);
        $url = rtrim(trim($url), "/");
        $url .= '/stripe/webhooks';
        return $url;
    }

    protected function isValidUrl($url)
    {
        // Validate URL
        if (filter_var($url, FILTER_VALIDATE_URL) === false)
            return false;

        return true;
    }

    public function setDebug(bool $value)
    {
        $this->debug = $value;
    }

    public function wasCapturedFromAdmin($object)
    {
        if (!empty($object['id']) && $this->cache->load("admin_captured_" . $object['id']))
        {
            return true;
        }

        if (!empty($object['payment_intent']) && is_string($object['payment_intent']) && $this->cache->load("admin_captured_" . $object['payment_intent']))
        {
            return true;
        }

        return false;
    }

    public function processTrialingSubscriptionOrder($order, $subscription)
    {
        if (is_string($subscription))
            $subscription = $this->config->getStripeClient()->subscriptions->retrieve($subscription);

        if ($subscription->status != "trialing")
        {
            // We are not interested in processing any other cases here.
            return;
        }

        // Trial subscriptions should still be fulfilled. A new order will be created when the trial ends.
        $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $status = $order->getConfig()->getStateDefaultStatus($state);
        $comment = __("Your trial period for order #%1 has started.", $order->getIncrementId());
        $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified = true);

        if ($this->subscriptionsHelper->isZeroAmountOrder($order))
        {
            if (!$order->getEmailSent())
            {
                $this->helper->sendNewOrderEmailFor($order, true);
            }

            // There will be no charge.succeeded event for trial subscription orders, so create the invoice here.
            // Then refund the amount that was not collected for the trial subscription. This is because when the
            // subscription activates, a new order will be created with a separate invoice.
            $this->helper->invoiceOrder($order, null, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
            $baseRefundTotal = $order->getBaseGrandTotal();
            $creditmemo = $this->creditmemoHelper->refundOfflineOrderBaseAmount($order, $baseRefundTotal);
            $this->creditmemoHelper->save($creditmemo);
        }

        $this->helper->saveOrder($order);
    }

    public function addOrderCommentWithEmail($order, $comment)
    {
        if (is_string($comment))
            $comment = __($comment);

        try
        {
            $this->orderCommentSender->send($order, $notify = true, $comment);
        }
        catch (\Exception $e)
        {
            // Just ignore this case
        }

        try
        {
            $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = true);
            $this->helper->saveOrder($order);
        }
        catch (\Exception $e)
        {
            $this->log($e->getMessage());
            $this->log($e->getTraceAsString());
        }
    }

    public function addOrderComment($order, $comment)
    {
        $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = false);
        $this->helper->saveOrder($order);
    }

    public function deduplicatePaymentMethod($object)
    {
        try
        {
            if (!empty($object['customer']))
            {
                $type = $object['type'];
                if (!empty($object[$type]['fingerprint']))
                {
                    $this->helper->deduplicatePaymentMethod(
                        $object['customer'],
                        $object['id'],
                        $type,
                        $object[$type]['fingerprint'],
                        $this->config->getStripeClient()
                    );
                }
            }
        }
        catch (\Exception $e)
        {
            return false;
        }

        return true;
    }

    protected function orderAgeLessThan($minutes, $order)
    {
        $created = strtotime($order->getCreatedAt());
        $now = time();
        return (($now - $created) < ($minutes * 60));
    }

    public function wasRefundedFromAdmin($object)
    {
        if (!empty($object['id']) && $this->cache->load("admin_refunded_" . $object['id']))
            return true;

        return false;
    }
}
