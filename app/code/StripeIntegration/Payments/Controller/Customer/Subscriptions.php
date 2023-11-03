<?php

namespace StripeIntegration\Payments\Controller\Customer;

use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Helper\Data as DataHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Controller\ResultFactory;

class Subscriptions extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;
    private $dataObjectFactory;
    private $helper;
    private $subscriptionsHelper;
    private $compare;
    private $dataHelper;
    private $order;
    private $stripeCustomer;
    private $subscriptionFactory;
    private $subscriptionProductFactory;
    private $config;
    private $stripeSubscriptionScheduleFactory;
    private $stripeSubscriptionFactory;
    private $session;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Customer\Model\Session $session,
        \Magento\Framework\DataObject\Factory $dataObjectFactory,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Model\SubscriptionFactory $subscriptionFactory,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Stripe\SubscriptionScheduleFactory $stripeSubscriptionScheduleFactory,
        \StripeIntegration\Payments\Model\Stripe\SubscriptionFactory $stripeSubscriptionFactory,
        \Magento\Sales\Model\Order $order
    )
    {
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);

        $this->session = $session;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->helper = $helper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->compare = $compare;
        $this->dataHelper = $dataHelper;
        $this->order = $order;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->subscriptionFactory = $subscriptionFactory;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->config = $config;
        $this->stripeSubscriptionScheduleFactory = $stripeSubscriptionScheduleFactory;
        $this->stripeSubscriptionFactory = $stripeSubscriptionFactory;

        if (!$session->isLoggedIn())
            $this->_redirect('customer/account/login');
    }

    public function execute()
    {
        $params = $this->getRequest()->getParams();

        if (isset($params['viewOrder']))
            return $this->viewOrder($params['viewOrder']);
        else if (isset($params['edit']))
            return $this->editSubscription($params['edit']);
        else if (isset($params['updateSuccess']))
            return $this->onUpdateSuccess();
        else if (isset($params['updateCancel']))
            return $this->onUpdateCancel();
        else if (isset($params['cancel']))
            return $this->cancelSubscription($params['cancel']);
        else if (isset($params['changeCard']))
            return $this->changeCard($params['changeCard'], $params['subscription_card']);
        else if (isset($params['changeShipping']))
            return $this->changeShipping($params['changeShipping']);
        else if (isset($params['reactivate']))
            return $this->reactivateSubscription($params['reactivate']);
        else if (!empty($params))
            $this->_redirect('stripe/customer/subscriptions');

        return $this->resultPageFactory->create();
    }

    protected function onUpdateCancel()
    {
        $this->subscriptionsHelper->cancelSubscriptionUpdate();
        return $this->_redirect('stripe/customer/subscriptions');
    }

    protected function onUpdateSuccess()
    {
        $this->helper->addSuccess(__("The subscription has been updated successfully."));
        return $this->_redirect('stripe/customer/subscriptions');
    }

    protected function viewOrder($incrementOrderId)
    {
        $this->order->loadByIncrementId($incrementOrderId);

        if ($this->order->getId())
            $this->_redirect('sales/order/view/order_id/' . $this->order->getId());
        else
        {
            $this->helper->addError("Order #$incrementOrderId could not be found!");
            $this->_redirect('stripe/customer/subscriptions');
        }
    }

    protected function cancelSubscription($subscriptionId)
    {
        try
        {
            if (!$this->stripeCustomer->getStripeId())
                throw new \Exception("Could not load customer account for subscription with ID $subscriptionId!");

            $this->subscriptionFactory->create()->cancel($subscriptionId);
            $this->helper->addSuccess(__("The subscription has been canceled successfully!"));
        }
        catch (\Exception $e)
        {
            $this->helper->addError(__("Sorry, the subscription could not be canceled. Please contact us for more help."));
            $this->helper->logError("Could not cancel subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }

        $this->_redirect('stripe/customer/subscriptions');
    }

    protected function changeCard($subscriptionId, $cardId)
    {
        try
        {
            if (!$this->stripeCustomer->getStripeId())
                throw new \Exception("Could not load customer account for subscription with ID $subscriptionId!");

            $subscription = \Stripe\Subscription::update($subscriptionId, ['default_payment_method' => $cardId]);

            $this->helper->addSuccess(__("The subscription has been updated."));
        }
        catch (\Exception $e)
        {
            $this->helper->addError("Sorry, the subscription could not be updated. Please contact us for more help.");
            $this->helper->logError("Could not edit subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }

        $this->_redirect('stripe/customer/subscriptions');
    }

    protected function editSubscription($subscriptionId)
    {
        try
        {
            if (!$this->stripeCustomer->getStripeId())
                throw new LocalizedException(__("Could not load customer account."));

            $subscriptionId = $this->getRequest()->getParam("edit", null);
            if (!$subscriptionId)
                throw new LocalizedException(__("Invalid subscription ID."));

            /** @var \StripeIntegration\Payments\Model\Stripe\Subscription $stripeSubscriptionModel */
            $stripeSubscriptionModel = $this->stripeSubscriptionFactory->create()->fromSubscriptionId($subscriptionId);
            $order = $stripeSubscriptionModel->getOrder();

            if (!$order || !$order->getId())
                throw new LocalizedException(__("Could not load order for this subscription."));

            $stripeSubscriptionModel->addToCart();

            $this->setSubscriptionUpdateDetails($stripeSubscriptionModel->getStripeObject(), [ $stripeSubscriptionModel->getProductId() ]);
            $product = $stripeSubscriptionModel->getOrderItem()->getProduct();
            $quoteItem = $this->helper->getQuote()->getItemByProduct($product);

            if (!$quoteItem) {
                throw new LocalizedException(__("Could not load the original order items."));
            }
            $quoteItemId = $quoteItem->getId();

            $configureUrl = "checkout/cart/configure/id/$quoteItemId/product_id/{$product->getId()}";
            return $this->_redirect($configureUrl);
        }
        catch (LocalizedException $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError("Could not update subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }
        catch (\Exception $e)
        {
            $this->helper->addError(__("Sorry, the subscription could not be updated. Please contact us for more help."));
            $this->helper->logError("Could not update subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }

        return $this->_redirect('stripe/customer/subscriptions');
    }

    protected function changeShipping($subscriptionId)
    {
        try
        {
            if (!$this->stripeCustomer->getStripeId())
                throw new LocalizedException(__("Could not load customer account."));

            if (!$subscriptionId)
                throw new LocalizedException(__("Invalid subscription ID."));

            $subscription = $this->config->getStripeClient()->subscriptions->retrieve($subscriptionId, []);
            $orderIncrementId = $this->subscriptionsHelper->getSubscriptionOrderID($subscription);
            if (!$orderIncrementId)
                throw new LocalizedException(__("This subscription is not associated with an order."));

            $order = $this->helper->loadOrderByIncrementId($orderIncrementId);

            if (!$order)
                throw new LocalizedException(__("Could not load order for this subscription."));

            $quote = $this->helper->getQuote();
            $quote->removeAllItems();
            $quote->removeAllAddresses();
            $extensionAttributes = $quote->getExtensionAttributes();
            $extensionAttributes->setShippingAssignments([]);

            $productIds = $this->subscriptionsHelper->getSubscriptionProductIDs($subscription);
            $items = $order->getItemsCollection();
            foreach ($items as $item)
            {
                $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromOrderItem($item);

                if ($subscriptionProductModel->isSubscriptionProduct() &&
                    $subscriptionProductModel->getProduct() &&
                    $subscriptionProductModel->getProduct()->isSaleable() &&
                    in_array($subscriptionProductModel->getProduct()->getId(), $productIds)
                    )
                {
                    $product = $subscriptionProductModel->getProduct();

                    if ($item->getParentItem() && $item->getParentItem()->getProductType() == "configurable")
                    {
                        $item = $item->getParentItem();
                        $product = $this->helper->loadProductById($item->getProductId());

                        if (!$product || !$product->isSaleable())
                            continue;
                    }

                    $request = $this->dataHelper->getBuyRequest($item);
                    $result = $quote->addProduct($product, $request);
                    if (is_string($result))
                        throw new LocalizedException(__($result));
                }
            }

            if (!$quote->hasItems())
                throw new LocalizedException(__("Sorry, this subscription product is currently unavailable."));

            $this->setSubscriptionUpdateDetails($subscription, $productIds);

            $quote->getShippingAddress()->setCollectShippingRates(false);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->helper->saveQuote($quote);
            try
            {
                if (!$order->getIsVirtual() && !$quote->getIsVirtual() && $order->getShippingMethod())
                {
                    $shippingMethod = $order->getShippingMethod();
                    $shippingAddress = $quote->getShippingAddress();
                    $shippingAddress->addData($order->getShippingAddress()->getData());
                    $shippingAddress->setCollectShippingRates(true)
                            ->collectShippingRates()
                            ->setShippingMethod($order->getShippingMethod())
                            ->save();
                }
            }
            catch (\Exception $e)
            {
                // The shipping address or method may not be available, ignore in this case
            }

            return $this->_redirect('checkout');
        }
        catch (LocalizedException $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError("Could not update subscription with ID $subscriptionId: " . $e->getMessage());
        }
        catch (\Exception $e)
        {
            $this->helper->addError(__("Sorry, the subscription could not be updated. Please contact us for more help."));
            $this->helper->logError("Could not update subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }

        return $this->_redirect('stripe/customer/subscriptions');
    }

    public function setSubscriptionUpdateDetails($subscription, $productIds)
    {
        // Last billed
        $startDate = $subscription->created;
        $date = $subscription->current_period_start;

        if ($startDate > $date)
        {
            $lastBilled = null;
        }
        else
        {
            $day = date("j", $date);
            $sup = date("S", $date);
            $month = date("F", $date);
            $year = date("y", $date);

            $lastBilled =  __("%1<sup>%2</sup>&nbsp;%3&nbsp;%4", $day, $sup, $month, $year);
        }

        // Next billing date
        $periodEnd = $subscription->current_period_end;
        if (!empty($subscription->schedule))
        {
            $schedule = $this->stripeSubscriptionScheduleFactory->create()->load($subscription->schedule);
            $nextBillingTimestamp = $schedule->getNextBillingTimestamp();

            if ($nextBillingTimestamp)
            {
                $periodEnd = $nextBillingTimestamp;
            }
        }
        $day = date("j", $periodEnd);
        $sup = date("S", $periodEnd);
        $month = date("F", $periodEnd);
        $year = date("y", $periodEnd);
        $nextBillingDate = __("%1<sup>%2</sup>&nbsp;%3&nbsp;%4", $day, $sup, $month, $year);

        $checkoutSession = $this->helper->getCheckoutSession();
        $checkoutSession->setSubscriptionUpdateDetails([
            "_data" => [
                "subscription_id" => $subscription->id,
                "original_order_increment_id" => $this->subscriptionsHelper->getSubscriptionOrderID($subscription),
                "product_ids" => $productIds,
                "current_period_end" => $periodEnd,
                "current_period_start" => $subscription->current_period_start,
                "proration_timestamp"=> time()
            ],
            "current_price_label" => $this->subscriptionsHelper->getInvoiceAmount($subscription) . " " . $this->subscriptionsHelper->formatDelivery($subscription),
            "last_billed_label" => $lastBilled,
            "next_billing_date" => $nextBillingDate
        ]);
    }

    protected function reactivateSubscription($subscriptionId)
    {
        try {
            if (!$this->stripeCustomer->getStripeId())
                throw new LocalizedException(__("Could not load customer account."));

            if (!$subscriptionId)
                throw new LocalizedException(__("Invalid subscription ID."));

            $subscriptionModel = $this->subscriptionFactory->create()->fromSubscriptionId($subscriptionId);
            $redirectPath = $subscriptionModel->reactivate();
            return $this->_redirect($redirectPath);
        }
        catch (LocalizedException $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError("Could not reactivate the subscription with ID $subscriptionId: " . $e->getMessage());
        }
        catch (\Exception $e) {
            $this->helper->addError(__("Sorry, unable to reactivate the subscription"));
            $this->helper->logError("Unable to reactivate the subscription $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }
    }
}
