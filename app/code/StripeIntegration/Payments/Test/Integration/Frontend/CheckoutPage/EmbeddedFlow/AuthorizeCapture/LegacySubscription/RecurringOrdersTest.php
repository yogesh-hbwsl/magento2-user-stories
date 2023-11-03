<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\LegacySubscription;

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
     * Tests subscription orders created using legacy Stripe Elements from version 2.9.3 and older.
     * The subscription has a Qty of 2, tax, shipping and a 10% discount coupon.
     * PaymentElement should be able to create a recurring order using the legacy subscription structure.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testRecurringOrders()
    {
        // First lets place an order with PaymentElement using an identical cart.
        $this->quote->create()
            ->setCustomer('Guest')
            ->addProduct('simple-monthly-subscription-product', 2)
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setCouponCode("10_percent")
            ->setPaymentMethod("SuccessCard");

        // Subtotal: $20
        // Discount: -$2
        // Shipping: $10
        // Tax: $1.49
        // Grand total: $29.49

        $order = $this->quote->placeOrder();
        $ordersCount = $this->tests->getOrdersCount();
        $this->tests->confirmSubscription($order);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Now lets create a subscription using the legacy method and associate it with $order

        $paymentMethod = $this->quote->createPaymentMethodFrom("4242424242424242", "California");

        $customer = $this->tests->stripe()->customers->create([
            "name" => "Joyce Strother",
            "email" => "joyce@example.com"
        ]);

        $coupon = $this->tests->stripe()->coupons->create([
          "percent_off" => "10",
          "currency" => "USD",
          "name" => "10pc Discount",
          "duration" => "forever"
        ]);

        $magentoProduct = $this->tests->helper()->loadProductBySku('simple-monthly-subscription-product');

        try
        {
            $product = $this->tests->stripe()->products->retrieve($magentoProduct->getId());
        }
        catch (\Exception $e)
        {
            $product = $this->tests->stripe()->products->create([
              "id" => $magentoProduct->getId(),
              "name" => $magentoProduct->getName(),
              "type" => "service"
            ]);
        }

        try
        {
            $plan = $this->tests->stripe()->plans->delete("1000usd-1MONTH-" . $magentoProduct->getId());
        }
        catch (\Exception $e)
        {

        }

        $plan = $this->tests->stripe()->plans->create([
            "amount" => "1000",
            "interval" => "month",
            "interval_count" => "1",
            "product" => $magentoProduct->getId(),
            "currency" => "usd",
            "id" => "1000usd-1MONTH-" . $magentoProduct->getId()
        ]);

        $this->tests->stripe()->paymentMethods->attach($paymentMethod->id, ['customer' => $customer->id]);

        $taxPercent = "8.25";
        $rates = $this->tests->stripe()->taxRates->all(['limit' => 00]);
        $shippingTaxRate = $productTaxRate = null;
        foreach ($rates->autoPagingIterator() as $rate)
        {
            if ($rate->percentage == 0 && !$rate->inclusive)
            {
                $shippingTaxRate = $rate;
            }

            if ($rate->percentage == $taxPercent && !$rate->inclusive)
            {
                $productTaxRate = $rate;
            }
        }
        if (!$shippingTaxRate)
        {
            $shippingTaxRate = $this->tests->stripe()->taxRates->create([
                "display_name" => "VAT",
                "description" => "0% VAT",
                "percentage" => "0",
                "inclusive" => "false"
            ]);
        }
        if (!$productTaxRate)
        {
            $productTaxRate = $this->tests->stripe()->taxRates->create([
                "display_name" => "VAT",
                "description" => $taxPercent . "% VAT",
                "percentage" => $taxPercent,
                "inclusive" => "false"
            ]);
        }

        $shippingLineItem = $this->tests->stripe()->invoiceItems->create([
            "customer" => $customer->id,
            "amount" => "1000",
            "currency" => "usd",
            "description" => "Shipping",
            "discountable" => "false",
            "tax_rates" => [ $shippingTaxRate->id ]
        ]);

        $metadata = [
            "Product ID" => $magentoProduct->getId(),
            "Customer ID" => "0",
            "Order #" => $order->getIncrementId(),
            "Module" => "Magento2 v2.9.3",
            "Shipping First Name" => "Jane",
            "Shipping Last Name" => "Doe",
            "Shipping Company" => "Jane Doe",
            "Shipping Street" => "1234 Doesnt Exst, Suite 123",
            "Shipping City" => "Culver City",
            "Shipping Region" => "Michigan",
            "Shipping Postcode" => "12345-6789",
            "Shipping Country" => "US",
            "Shipping Telephone" => "+447890123456"
        ];

        $subscription = $this->tests->stripe()->subscriptions->create([
            "customer" => $customer->id,
            "plan" => $plan->id,
            "quantity" => 2,
            "default_payment_method" => $paymentMethod->id,
            "enable_incomplete_payments" => "true",
            "metadata" => $metadata,
            "expand" => [ "latest_invoice.payment_intent" ],
            "default_tax_rates" => [ $productTaxRate->id ],
            "coupon" => $coupon->id
        ]);

        $recurringShippingLineItem = $this->tests->stripe()->invoiceItems->create([
            "customer" => $customer->id,
            "amount" => "1000",
            "currency" => "usd",
            "description" => "Shipping",
            "discountable" => "false",
            "tax_rates" => [ $shippingTaxRate->id ],
            "subscription" => $subscription->id
        ]);

        $this->tests->stripe()->paymentIntents->update($subscription->latest_invoice->payment_intent->id, [
            "description" => "2 x Simple Monthly Subscription",
            "metadata" => $metadata
        ]);

        // Done, now trigger a recurring order webhook event

        $this->tests->event()->trigger("invoice.payment_succeeded", $subscription->latest_invoice, ['billing_reason' => 'subscription_cycle']);

        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());

        $this->tests->compare($order->getData(), [
            "discount_amount" => $newOrder->getDiscountAmount(),
            "tax_amount" => $newOrder->getTaxAmount(),
            "grand_total" => $newOrder->getGrandTotal(),
        ]);
    }
}
