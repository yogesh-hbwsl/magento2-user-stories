<?php

namespace StripeIntegration\Payments\Test\Integration\Adminarea\StripeInvoice\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $helper;
    private $quote;
    private $tests;
    private $paymentMethodBlock;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);

        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->paymentMethodBlock = $this->objectManager->get(\StripeIntegration\Payments\Block\Adminhtml\SelectPaymentMethod::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testNormalCart()
    {
        $this->quote->createAdmin()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeInvoice");

        $this->tests->startWebhooks();
        $this->paymentMethodBlock->getSavedPaymentMethods(); // Creates the customer object
        $order = $this->quote->placeOrder();
        $this->tests->runWebhooks();

        // Check the order
        $order = $this->tests->refreshOrder($order);
        $this->tests->compare($order->debug(), [
            "state" => "pending_payment",
            "status" => "pending_payment",
            "grand_total" => 53.30,
            "total_due" => $order->getGrandTotal(),
            "total_invoiced" => $order->getGrandTotal()
        ]);

        // Check the Magento invoice
        $invoicesCollection = $order->getInvoiceCollection();
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertNotEmpty($invoice);
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_OPEN, $invoice->getState());

        // Check the Stripe invoice
        $invoiceId = $order->getPayment()->getAdditionalInformation("invoice_id");
        $this->assertNotEmpty($invoiceId);
        $stripeInvoice = $this->tests->stripe()->invoices->retrieve($invoiceId, []);

        $this->tests->compare($stripeInvoice, [
            "amount_due" => $order->getGrandTotal() * 100,
            "amount_paid" => 0,
            "customer_address" => [
                "city" => $order->getBillingAddress()->getCity(),
                "country" => $order->getBillingAddress()->getCountryId(),
                "line1" => $order->getBillingAddress()->getStreet()[0],
                "postal_code" => $order->getBillingAddress()->getPostcode(),
                "state" => $order->getBillingAddress()->getRegion()
            ],
            "customer_email" => $order->getCustomerEmail(),
            "customer_name" => $order->getBillingAddress()->getFirstname() . " " . $order->getBillingAddress()->getLastname(),
            "customer_phone" => $order->getBillingAddress()->getTelephone()
        ]);

        // Pay the invoice
        $paymentMethod = $this->tests->stripe()->paymentMethods->attach("pm_card_visa", [
            'customer' => $stripeInvoice->customer
        ]);
        $stripeInvoice = $this->tests->stripe()->invoices->pay($invoiceId, [
            'payment_method' => $paymentMethod->id
        ]);
        $this->assertEquals($order->getGrandTotal() * 100, $stripeInvoice->amount_paid);

        // Check the order
        $this->tests->runWebhooks();
        $order = $this->tests->refreshOrder($order);

        // Check if Radar risk value is been set to the order
        $this->assertIsNumeric($order->getStripeRadarRiskScore());
        $this->assertGreaterThanOrEqual(0, $order->getStripeRadarRiskScore());
        $this->assertNotEquals('NA', $order->getStripeRadarRiskLevel());

        // Check Stripe Payment method
        $paymentMethod = $this->tests->loadPaymentMethod($order->getId());
        $this->assertEquals('card', $paymentMethod->getPaymentMethodType());

        $this->tests->compare($order->debug(), [
            "state" => "processing",
            "status" => "processing",
            "total_due" => 0,
            "total_paid" => $order->getGrandTotal()
        ]);
    }
}
