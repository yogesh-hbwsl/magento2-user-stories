<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\ZeroAmount;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\SessionFactory as CheckoutSessionFactory;
use PHPUnit\Framework\Constraint\StringContains;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $checkoutSession;
    private $eventManager;
    private $helper;
    private $objectManager;
    private $orderRepository;
    private $orderSender;
    private $quote;
    private $stripeConfig;
    private $tests;
    private $transportBuilder;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);

        $this->checkoutSession = $this->objectManager->get(CheckoutSessionFactory::class)->create();
        $this->transportBuilder = $this->objectManager->get(\Magento\TestFramework\Mail\Template\TransportBuilderMock::class);
        $this->eventManager = $this->objectManager->get(\Magento\Framework\Event\ManagerInterface::class);
        $this->orderSender = $this->objectManager->get(\Magento\Sales\Model\Order\Email\Sender\OrderSender::class);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->orderRepository = $this->objectManager->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testZeroAmountCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("ZeroAmount")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $setupIntent = $this->tests->confirmSubscription($order);

        $stripe = $this->stripeConfig->getStripeClient();

        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $stripe->customers->retrieve($customerId);
        $this->assertEquals(1, count($customer->subscriptions->data));
        $subscription = $customer->subscriptions->data[0];
        $this->assertNotEmpty($subscription->latest_invoice);
        $invoiceId = $subscription->latest_invoice;

        // Get the current orders count
        $ordersCount = $this->tests->getOrdersCount();

        $invoice = $stripe->invoices->retrieve($invoiceId, ['expand' => ['charge']]);
        $this->assertNotEmpty($invoice->subscription);
        $subscriptionId = $invoice->subscription;
        $this->assertEmpty($invoice->charge);
        $this->assertEquals(0, $invoice->amount_due);
        $this->assertEquals(0, $invoice->amount_paid);
        $this->assertEquals(0, $invoice->amount_remaining);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Check if Radar risk value is been set to the order
        $this->assertIsNotNumeric($order->getStripeRadarRiskScore());
        $this->assertEquals('NA', $order->getStripeRadarRiskLevel());

        // Check Stripe Payment method
        $paymentMethod = $this->tests->loadPaymentMethod($order->getId());
        $this->assertEquals('', $paymentMethod->getPaymentMethodType());

        if ($this->tests->magento("<", "2.4") || $this->tests->magento(">=", "2.4.6"))
        {
            $state = "closed";
            $status = "closed";
        }
        else
        {
            // In v3.2.8 we create a pending invoice and do not refund the order
            // In v3.4.x we invoice & refund the order
            // The free product is virtual and the trial subscription amount was refunded, so there is no need to ship any items
            // Magento marks the order as closed, ideally it should be complete because the free item has not been refunded
            $state = "complete";
            $status = "closed";
        }
        $this->assertEquals($state, $order->getState());
        $this->assertEquals($status, $order->getStatus());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());

        // Check that an invoice was created
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->getSize());
        $this->assertEquals(1, $order->getCreditmemosCollection()->getSize());

        // End the trial
        $subscription = $this->tests->endTrialSubscription($subscriptionId);

        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $this->tests->compare($order->debug(), [
            'state' => $state,
            'status' => $status,
            'total_paid' => $order->getGrandTotal(),
            'total_refunded' => $order->getGrandTotal()
        ]);

        // Check that an invoice was created
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertNotEmpty($invoicesCollection);
        $this->assertEquals(1, $invoicesCollection->getSize());

        $invoice = $invoicesCollection->getFirstItem();

        $this->assertEquals(2, count($invoice->getAllItems()));
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());

        // Check the newly created order
        $newOrder = $this->tests->getLastOrder();
        $transactions = $this->helper->getOrderTransactions($newOrder);
        $this->assertEquals(1, count($transactions));
        foreach ($transactions as $key => $transaction)
        {
            $this->assertEquals("capture", $transaction->getTxnType());
            $this->assertEmpty($transaction->getAdditionalInformation("amount"));
        }
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("complete", $newOrder->getState());
        $this->assertEquals("complete", $newOrder->getStatus());
        $this->assertEquals(10.83, $newOrder->getGrandTotal());
        $this->assertEquals($newOrder->getGrandTotal(), $newOrder->getTotalPaid());
        $this->assertEquals(1, $newOrder->getInvoiceCollection()->getSize());
    }
}
