<?php

namespace StripeIntegration\Payments\Test\Integration\Helper;

class Tests
{
    protected $objectManager = null;
    protected $quoteRepository = null;
    protected $productRepository = null;
    protected $tests = null;
    protected $lastWebhookEvent = null;
    protected $processedEvents = [];

    private $address;
    private $checkoutHelper;
    private $checkoutSessionsCollectionFactory;
    private $compare;
    private $creditmemoFactory;
    private $creditmemoItemInterfaceFactory;
    private $creditmemoService;
    private $dataHelper;
    private $event;
    private $helper;
    private $invoiceService;
    private $orderFactory;
    private $paymentElementFactory;
    private $productMetadata;
    private $refundOrder;
    private $shipOrder;
    private $stripeConfig;
    private $test;
    private $webhooksHelper;
    private $stripePaymentMethodFactory;
    private $resourceStripePaymentMethod;
    private $taxRateRepository;
    private $searchCriteriaBuilder;
    private $subscriptionOptionsFactory;

    public function __construct($test)
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quoteRepository = $this->objectManager->create(\Magento\Quote\Api\CartRepositoryInterface::class);
        $this->productRepository = $this->objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $this->orderFactory = $this->objectManager->get(\Magento\Sales\Model\OrderFactory::class);
        $this->creditmemoItemInterfaceFactory = $this->objectManager->get(\Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory::class);
        $this->refundOrder = $this->objectManager->get(\Magento\Sales\Api\RefundOrderInterface::class);
        $this->creditmemoFactory = $this->objectManager->get(\Magento\Sales\Model\Order\CreditmemoFactory::class);
        $this->creditmemoService = $this->objectManager->get(\Magento\Sales\Model\Service\CreditmemoService::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->address = $this->objectManager->get(\StripeIntegration\Payments\Test\Integration\Helper\Address::class);
        $this->checkoutSessionsCollectionFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\ResourceModel\CheckoutSession\CollectionFactory::class);
        $this->event = new \StripeIntegration\Payments\Test\Integration\Helper\Event($test);
        $this->checkoutHelper = new \StripeIntegration\Payments\Test\Integration\Helper\Checkout($test);
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($test);
        $this->test = $test;
        $this->invoiceService = $this->objectManager->get(\Magento\Sales\Model\Service\InvoiceService::class);
        $this->paymentElementFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentElementFactory::class);
        $this->shipOrder = $this->objectManager->get(\Magento\Sales\Api\ShipOrderInterface::class);
        $this->productMetadata = $this->objectManager->get(\Magento\Framework\App\ProductMetadataInterface::class);
        $this->dataHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Data::class);
        $this->webhooksHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Webhooks::class);
        $this->stripePaymentMethodFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\StripePaymentMethodFactory::class);
        $this->resourceStripePaymentMethod = $this->objectManager->get(\StripeIntegration\Payments\Model\ResourceModel\StripePaymentMethod::class);
        $this->taxRateRepository = $this->objectManager->get(\Magento\Tax\Api\TaxRateRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(\Magento\Framework\Api\SearchCriteriaBuilder::class);
        $this->subscriptionOptionsFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\SubscriptionOptionsFactory::class);
        $this->webhooksHelper->setDebug(true);
    }

    public function refundOffline($invoice, $itemSkus)
    {
        $items = [];

        foreach ($invoice->getAllItems() as $invoiceItem)
        {
            if ($invoiceItem->getOrderItem()->getParentItem())
                continue;

            $sku = $invoiceItem->getSku();

            if(in_array($sku, $itemSkus))
            {
                $creditmemoItem = $this->creditmemoItemInterfaceFactory->create();
                $items[] = $creditmemoItem
                            ->setQty($invoiceItem->getQty())
                            ->setOrderItemId($invoiceItem->getOrderItemId());
            }
        }

        // Create the credit memo
        $this->refundOrder->execute($invoice->getOrderId(), $items, true, false);
    }

    public function refundOnline($invoice, $itemQtys, $baseShippingAmount = 0, $adjustmentPositive = 0, $adjustmentNegative = 0)
    {
        if (empty($invoice) || !$invoice->getId())
            throw new \Exception("Invalid invoice");

        $qtys = [];

        foreach ($invoice->getAllItems() as $invoiceItem)
        {
            if ($invoiceItem->getOrderItem()->getParentItem())
                continue;

            $sku = $invoiceItem->getSku();

            if(isset($itemQtys[$sku]))
                $qtys[$invoiceItem->getOrderItem()->getId()] = $itemQtys[$sku];
        }

        if (count($itemQtys) != count($qtys))
            throw new \Exception("Specified SKU not found in invoice items.");

        $params = [
            "qtys" => $qtys,
            "shipping_amount" => $baseShippingAmount,
            "adjustment_positive" => $adjustmentPositive,
            "adjustment_negative" => $adjustmentNegative
        ];

        if (empty($invoice->getTransactionId()))
            throw new \Exception("Cannot refund online because the invoice has no transaction ID");

        $creditmemo = $this->creditmemoFactory->createByInvoice($invoice, $params);

        // Create the credit memo
        return $this->creditmemoService->refund($creditmemo);
    }

    public function invoiceOnline($order, $itemQtys, $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE)
    {
        $orderItemIDs = [];
        $orderItemQtys = [];

        foreach ($order->getAllVisibleItems() as $orderItem)
        {
            $orderItemIDs[$orderItem->getSku()] = $orderItem->getId();
        }

        foreach ($itemQtys as $sku => $qty)
        {
            if (isset($orderItemIDs[$sku]))
            {
                $id = $orderItemIDs[$sku];
                $orderItemQtys[$id] = $qty;
            }
        }

        $invoice = $this->invoiceService->prepareInvoice($order, $orderItemQtys);
        $invoice->setRequestedCaptureCase($captureCase);
        $order->setIsInProcess(true);
        $invoice->register();
        $invoice->pay();
        $this->helper->saveOrder($order);
        return $this->helper->saveInvoice($invoice);
    }

    public function stripe()
    {
        return $this->stripeConfig->getStripeClient();
    }

    public function config()
    {
        return $this->stripeConfig;
    }

    public function event()
    {
        return $this->event;
    }

    public function saveProduct($product)
    {
        return $this->productRepository->save($product);
    }

    public function getProduct($sku)
    {
        return $this->productRepository->get($sku);
    }

    public function getOrdersCount()
    {
        return $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->count();
    }

    public function getLastOrder()
    {
        return $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->setOrder('increment_id','DESC')->getFirstItem();
    }

    public function getOrderBySortPosition($sortPosition)
    {
        $orders = $this->objectManager->create('Magento\Sales\Model\Order')->getCollection()->setOrder('increment_id','DESC');

        foreach ($orders as $order)
        {
            if ($sortPosition == 1)
                return $order;

            $sortPosition--;
        }

        return null;
    }

    public function getLastCheckoutSession()
    {
        $collection = $this->checkoutSessionsCollectionFactory->create()
            ->addFieldToSelect('*')
            ->setOrder('created_at','DESC');

        $model = $collection->getFirstItem();

        if ($model->getCheckoutSessionId())
            return $this->stripe()->checkout->sessions->retrieve($model->getCheckoutSessionId(), ['expand' => ['payment_intent', 'subscription']]);

        throw new \Exception("There are no Stripe Checkout sessions cached.");
    }

    public function getStripeCustomer()
    {
        $customerModel = $this->helper->getCustomerModel();
        if ($customerModel->getStripeId())
            return $this->stripe()->customers->retrieve($customerModel->getStripeId());

        return null;
    }

    public function checkout()
    {
        return $this->checkoutHelper;
    }

    public function compare($object, array $expectedValues)
    {
        return $this->compare->object($object, $expectedValues);
    }

    public function helper()
    {
        return $this->helper->clearCache();
    }

    public function refreshOrder($order)
    {
        if (!$order->getId())
            throw new \Exception("No order ID provided");

        return $this->orderFactory->create()->load($order->getId());
    }

    public function address()
    {
        return $this->address;
    }

    public function assertCheckoutSessionsCountEquals($count)
    {
        $sessions = $this->checkoutSessionsCollectionFactory->create()->addFieldToSelect('*');
        $this->test->assertEquals($count, $sessions->getSize());
        $session = $sessions->getFirstItem();
        $this->test->assertStringContainsString("cs_test_", $session->getCheckoutSessionId());
    }

    public function endTrialSubscription($subscriptionId)
    {
        // End the trial
        $this->stripe()->subscriptions->update($subscriptionId, ['trial_end' => "now"]);
        $subscription = $this->stripe()->subscriptions->retrieve($subscriptionId, ['expand' => ['latest_invoice']]);

        // Trigger webhook events for the trial end
        $this->event()->trigger("charge.succeeded", $subscription->latest_invoice->charge);
        $this->event()->trigger("invoice.payment_succeeded", $subscription->latest_invoice->id, ['billing_reason' => 'subscription_cycle']);
        return $subscription;
    }

    public function confirm($order, $params = [])
    {
        $paymentElement = $this->paymentElementFactory->create()->fromQuoteId($order->getQuoteId());
        $paymentIntent = $paymentElement->getPaymentIntent();
        $this->event()->triggerPaymentIntentEvents($paymentIntent);

        return $paymentIntent;
    }

    public function confirmSubscription($order, $triggerEvents = true)
    {
        $paymentElement = $this->paymentElementFactory->create()->fromQuoteId($order->getQuoteId());
        $this->test->assertNotEmpty($paymentElement->getSubscriptionId(), "The subscription could not be created");

        if ($paymentElement->getSetupIntentId())
        {
            $this->event()->trigger("setup_intent.succeeded", $paymentElement->getSetupIntentId());
            $obj = $setupIntent = $this->stripe()->setupIntents->retrieve($paymentElement->getSetupIntentId(), []);
        }
        else if ($paymentElement->getPaymentIntentId())
        {
            $subscription = $paymentElement->getSubscription();
            $this->event()->triggerSubscriptionEvents($subscription);
            $obj = $paymentIntent = $this->stripe()->paymentIntents->retrieve($paymentElement->getPaymentIntentId(), []);
        }
        else if ($paymentElement->getSubscription())
        {
            // Trial or Zero amount subscription orders
            $subscription = $paymentElement->getSubscription();
            $this->event()->triggerSubscriptionEvents($subscription);
            $obj = $subscription;
        }
        else
        {
            throw new \Exception("Cannot confirm subscription");
        }

        return $obj;
    }

    public function confirmCheckoutSession($order, $cart, $paymentMethod = "card", $address = "California")
    {
        // Confirm the payment
        $session = $this->checkout()->retrieveSession($order, $cart);

        /** @var \Stripe\StripeObject $response */
        $response = $this->checkout()->confirm($session, $order, $paymentMethod, $address);

        if (!empty($response->payment_intent))
        {
            $this->checkout()->authenticate($response->payment_intent, $paymentMethod);
            $paymentIntent = $this->stripe()->paymentIntents->retrieve($response->payment_intent->id);

            // Trigger webhooks
            /** @var \Stripe\StripeObject $customer */
            $customer = $this->stripe()->customers->retrieve($response->customer->id);
            if (!empty($customer->subscriptions->data))
            {
                foreach ($customer->subscriptions->data as $subscription)
                {
                    $this->event()->triggerSubscriptionEvents($subscription);
                }

                return $paymentIntent;
            }
            else if ($response->payment_intent)
            {
                $this->event()->triggerPaymentIntentEvents($response->payment_intent->id);

                return $paymentIntent;
            }
            else /* if ($response->setup_intent) */
                throw new \Exception("Setup intent not implemented.");
        }
        else if (!empty($response->setup_intent))
        {
            $this->event()->triggerEvent("checkout.session.completed", $response->session_id);
            return $response;
        }
        else
        {
            throw new \Exception("The checkout session has neither a payment intent, nor a setup intent.");
        }
    }

    public function shipOrder($orderId)
    {
        $this->shipOrder->execute($orderId);
    }

    public function reInitConfig()
    {
        $this->objectManager->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
        $this->objectManager->create(\Magento\Store\Model\StoreManagerInterface::class)->reinitStores();
    }

    public function magento($operator, $version)
    {
        $magentoVersion = $this->productMetadata->getVersion();
        return version_compare($magentoVersion, $version, $operator);
    }

    public function startWebhooks()
    {
        if (!empty($this->lastWebhookEvent))
            return;

        $name = "Automated test suite product " . rand();

        \StripeIntegration\Payments\Helper\Logger::log("Starting webhooks.");
        $product = $this->stripe()->products->create(['name' => $name]);
        $events = $this->stripe()->events->all(['limit' => 100, 'created' => ['gt' => time() - 60], 'types' => ['product.created']]);

        $event = null;
        foreach ($events->autoPagingIterator() as $eventData)
        {
            if ($eventData->data->object->name == $name)
            {
                $event = $eventData;
            }
        }

        if (empty($event))
            throw new \Exception("Could not start webhooks");

        $this->processedEvents[$event->id] = true;
        $this->lastWebhookEvent = $event;
        $this->stripe()->products->delete($product->id);
    }

    public function runWebhooks($wait = 1)
    {
        sleep($wait); // Wait for Stripe to generate all events
        $types = \StripeIntegration\Payments\Helper\WebhooksSetup::$enabledEvents;
        $events = $this->stripe()->events->all(['limit' => 100, 'created' => ["gte" => $this->lastWebhookEvent->created]]);
        $sortedEvents = [];

        foreach ($events->autoPagingIterator() as $event)
        {
            if (in_array($event->type, $types) && !isset($this->processedEvents[$event->id]))
            {
                $sortedEvents[] = $event;
                $this->processedEvents[$event->id] = true;

                if ($event->created > $this->lastWebhookEvent->created)
                {
                    $this->lastWebhookEvent = $event;
                }
            }
        }

        // Trigger the events in the reverse order that Stripe listed them
        for ($i = count($sortedEvents) - 1; $i >= 0; $i--)
        {
            $this->event()->dispatchEvent($sortedEvents[$i]);
        }
    }

    public function orderHasComment($order, string $text)
    {
        $statuses = $order->getAllStatusHistory();

        foreach ($statuses as $status)
        {
            $comment = $status['comment'];
            if (strpos($comment, $text) !== false)
            {
                return true;
            }
        }

        return false;
    }

    public function getBuyRequest($order)
    {
        foreach ($order->getAllVisibleItems() as $orderItem)
        {
            $buyRequest = $this->dataHelper->getConfigurableProductBuyRequest($orderItem);
            return $buyRequest;
        }

        throw new \Exception("No buyRequest found for the order");
    }

    public function loadPaymentMethod($orderId)
    {
        $modelClass = $this->stripePaymentMethodFactory->create();
        $this->resourceStripePaymentMethod->load($modelClass, $orderId, 'order_id');
        return $modelClass;
    }

    public function updateTaxRate($taxRateCode, $newRate)
    {
        // Build search criteria to find the tax rate by code
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('code', $taxRateCode, 'eq')
            ->create();

        // Retrieve the tax rates matching the search criteria
        $taxRates = $this->taxRateRepository->getList($searchCriteria)->getItems();

        // If the tax rate was found, update its rate
        if (!empty($taxRates)) {
            // There should be only one tax rate with a specific code
            $taxRate = reset($taxRates);

            // Update the rate
            $taxRate->setRate($newRate);

            // Save the updated tax rate
            $this->taxRateRepository->save($taxRate);

            return true;
        }

        return false;
    }

    public function loadSubscriptionOptions($productId)
    {
        return $this->subscriptionOptionsFactory->create()->load($productId);
    }
}
