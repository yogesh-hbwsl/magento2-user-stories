<?php

namespace StripeIntegration\Payments\Plugin\Checkout\Controller\Cart;

use Magento\Framework\Exception\LocalizedException;

class UpdateItemOptions
{
    /**
     * @var \Magento\Framework\Controller\Result\RedirectFactory
     */
    protected $resultRedirectFactory;
    private $messageManager;
    private $url;
    private $config;
    private $subscriptionsHelper;
    private $helper;
    private $controller;
    private $configurableProductFactory;
    private $subscriptionProductFactory;
    private $subscriptionUpdatesHelper;

    public function __construct(
        \Magento\Framework\Controller\Result\RedirectFactory $resultRedirectFactory,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Framework\UrlInterface $url,
        \Magento\ConfigurableProduct\Model\Product\Type\ConfigurableFactory $configurableProductFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Helper\SubscriptionUpdates $subscriptionUpdatesHelper
    ) {
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->messageManager = $messageManager;
        $this->url = $url;
        $this->config = $config;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->helper = $helper;
        $this->configurableProductFactory = $configurableProductFactory;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->subscriptionUpdatesHelper = $subscriptionUpdatesHelper;
    }

    public function aroundExecute(
        \Magento\Checkout\Controller\Cart\UpdateItemOptions $subject,
        \Closure $proceed
    ) {
        try
        {
            $this->controller = $subject;
            $isSubscriptionUpdate = $this->config->isSubscriptionsEnabled() && $this->subscriptionsHelper->isSubscriptionUpdate();
            if ($isSubscriptionUpdate)
            {
                $this->validateAllowedSubscriptionUpdate();
            }

            $result = $proceed();

            if ($result instanceof \Magento\Framework\Controller\Result\Redirect && $isSubscriptionUpdate)
            {
                $redirectResult = $this->resultRedirectFactory->create();
                $redirectResult->setPath('checkout');
                $this->messageManager->getMessages(true); // This will clear all success messages
                return $redirectResult;
            }

            return $result;

        } catch (LocalizedException $e) {
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            return $this->goBack();
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('We can\'t update the item right now.'));
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            return $this->goBack();
        }
    }

    protected function goBack($backUrl = null)
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $request = $this->controller->getRequest();
        $refererUrl = $request->getServer('HTTP_REFERER');
        $resultRedirect->setUrl($refererUrl);
        return $resultRedirect;
    }

    protected function validateAllowedSubscriptionUpdate()
    {
        $request = $this->controller->getRequest();
        $productId = $request->getParam('product', null);
        $superAttribute = $request->getParam('super_attribute', null);

        if (!$productId || !$superAttribute)
            return;

        $product = $this->helper->loadProductById($productId);
        if (!$product || !$product->getId())
            return;

        if ($product->getTypeId() != 'configurable')
            return;

        $subscriptionUpdateDetails = $this->subscriptionUpdatesHelper->getSubscriptionUpdateDetails();
        if (empty($subscriptionUpdateDetails['_data']['product_ids']))
            return;

        $selectedProduct = $this->configurableProductFactory->create()->getProductByAttributes($superAttribute, $product);

        if (in_array($selectedProduct->getId(), $subscriptionUpdateDetails['_data']['product_ids']))
            return; // The product selection has not changed

        $selectedSubscriptionProduct = $this->subscriptionProductFactory->create()->fromProductId($selectedProduct->getId());

        if (!$selectedSubscriptionProduct->isSubscriptionProduct())
        {
            throw new LocalizedException(__('This option is not a subscription. If you would like to cancel your subscription, you can do so from the customer account section.'));
        }

        if ($selectedSubscriptionProduct->hasStartDate())
        {
            throw new LocalizedException(__('This option is not available because it will reset the subscription billing date.'));
        }

        if (!$selectedSubscriptionProduct->getIsSaleable())
        {
            throw new LocalizedException(__('Sorry, this option is not available right now.'));
        }
    }
}
