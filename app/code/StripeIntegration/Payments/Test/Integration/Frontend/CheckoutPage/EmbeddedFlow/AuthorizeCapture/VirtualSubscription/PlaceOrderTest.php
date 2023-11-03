<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\VirtualSubscription;

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
    private $orderSender;
    private $quote;
    private $tests;
    private $transportBuilder;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);

        $this->checkoutSession = $this->objectManager->get(CheckoutSessionFactory::class)->create();
        $this->transportBuilder = $this->objectManager->get(\Magento\TestFramework\Mail\Template\TransportBuilderMock::class);
        $this->eventManager = $this->objectManager->get(\Magento\Framework\Event\ManagerInterface::class);
        $this->orderSender = $this->objectManager->get(\Magento\Sales\Model\Order\Email\Sender\OrderSender::class);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testVirtualSubscription()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->addProduct('virtual-monthly-subscription-product', 1)
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $invoicesCollection = $order->getInvoiceCollection();

        $this->assertEquals("complete", $order->getState());
        $this->assertEquals("complete", $order->getStatus());
        $this->assertNotEmpty($invoicesCollection);
        $this->assertEquals(1, $invoicesCollection->getSize());

        $invoice = $invoicesCollection->getFirstItem();

        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Stripe checks
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertEquals(1, count($customer->subscriptions->data));
        $subscription = $customer->subscriptions->data[0];
        $this->assertNotEmpty($subscription->latest_invoice);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $transactions = $this->helper->getOrderTransactions($order);
        $this->assertEquals(1, count($transactions));
    }
}
