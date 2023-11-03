<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\SubscriptionInitialFee;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RecurringOrdersTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $helper;
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testRecurringOrders()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart('SubscriptionInitialFee')
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $ordersCount = $this->tests->getOrdersCount();
        $this->tests->confirmSubscription($order);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Stripe checks
        $orderTotal = 1908;
        $subscriptionTotal = 1583; // $10 for the item, $5 for the shipping, $0.83 for tax
        $initialFee = 325; // $3 fee + $0.25 tax

        // Trigger webhook events for recurring order
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId, []);
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice);
        $invoice->amount = $subscriptionTotal; // Remove the initial fee from the next invoice
        $invoice->amount_paid = $subscriptionTotal;

        $this->tests->event()->trigger("charge.succeeded", $invoice->charge);
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoice->id, ['billing_reason' => 'subscription_cycle']);

        // Make sure a new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Get the recurring order
        $recurringOrder = $this->tests->getLastOrder();

        // Order checks
        $this->compare->object($recurringOrder->getData(), [
            'grand_total' => round(floatval($order->getGrandTotal()) - ($initialFee / 100), 2),
            'shipping_amount' => $order->getShippingAmount(),
            'tax_amount' => round(floatval($order->getTaxAmount()) - 0.25, 2), // Minus intial fee tax
        ]);
    }
}
