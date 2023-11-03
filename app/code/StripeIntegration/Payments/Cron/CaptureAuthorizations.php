<?php

namespace StripeIntegration\Payments\Cron;

use StripeIntegration\Payments\Exception\SkipCaptureException;

class CaptureAuthorizations
{
    private $config;
    private $cache;
    private $orderCollection;
    private $emailHelper;
    private $transportBuilder;
    private $helper;
    private $multishippingHelper;
    private $multishippingQuoteCollection;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Sales\Model\ResourceModel\Order\Collection $orderCollection,
        \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Email $emailHelper,
        \StripeIntegration\Payments\Helper\Multishipping $multishippingHelper,
        \StripeIntegration\Payments\Model\ResourceModel\Multishipping\Quote\Collection $multishippingQuoteCollection
    ) {
        $this->config = $config;
        $this->cache = $cache;
        $this->orderCollection = $orderCollection;
        $this->emailHelper = $emailHelper;
        $this->transportBuilder = $transportBuilder;
        $this->helper = $helper;
        $this->multishippingHelper = $multishippingHelper;
        $this->multishippingQuoteCollection = $multishippingQuoteCollection;
    }

    public function execute()
    {
        $quoteModels = $this->multishippingQuoteCollection->getUncaptured(0, 1);
        $transactionIds = [];

        foreach ($quoteModels as $quoteModel)
        {
            $paymentIntentId = $quoteModel->getPaymentIntentId();
            $transactionIds[$paymentIntentId] = $quoteModel;
        }

        foreach ($transactionIds as $paymentIntentId => $quoteModel)
        {
            $orders = $this->helper->getOrdersByTransactionId($paymentIntentId);
            if (empty($orders))
                continue;

            try
            {
                $this->multishippingHelper->captureOrdersFromCronJob($orders, $paymentIntentId);
                $quoteModel->setCaptured(true);
                $quoteModel->save();
            }
            catch (SkipCaptureException $e)
            {
                if ($e->getCode() == SkipCaptureException::ORDERS_NOT_PROCESSED)
                {
                    if (!$quoteModel->getWarningEmailSent())
                    {
                        $this->sendReminderEmail($paymentIntentId, $orders);
                        $quoteModel->setWarningEmailSent(true);
                        $quoteModel->save();
                    }
                }
                else if ($e->getCode() == SkipCaptureException::ZERO_AMOUNT)
                {
                    // The orders were likely canceled
                }
                else
                {
                    $this->helper->logError($e->getMessage());
                }
            }
            catch (\Exception $e)
            {
                $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            }
        }
    }

    protected function sendReminderEmail($paymentIntentId, $orderCollection)
    {
        $generalName = $this->emailHelper->getName('general');
        $generalEmail = $this->emailHelper->getEmail('general');

        $incrementIds = [];
        foreach ($orderCollection as $order)
            $incrementIds[] = "#" . $order->getIncrementId();

        $templateVars = [ 'orderNumbers'  => implode(", ", $incrementIds) ];

        $this->emailHelper->send('stripe_expiring_authorization', $generalName, $generalEmail, $generalName, $generalEmail, $templateVars);
    }
}
