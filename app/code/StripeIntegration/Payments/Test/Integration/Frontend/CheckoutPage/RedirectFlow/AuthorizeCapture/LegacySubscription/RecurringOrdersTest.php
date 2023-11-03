<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\LegacySubscription;

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
     * Tests subscription orders created using legacy Stripe Checkout from version 2.6.1 and older.
     * The subscription has a Qty of 2, tax and shipping
     * We should be able to create a recurring order using the legacy subscription structure.
     *
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     */
    public function testRecurringOrders()
    {
        // First lets place an order with Stripe Checkout using an identical cart.
        $this->quote->create()
            ->setCustomer('Guest')
            ->addProduct('simple-monthly-subscription-product', 2)
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeCheckout");

        // Subtotal: $20
        // Shipping: $10
        // Tax: $1.65
        // Grand total: $31.65

        $order = $this->quote->placeOrder();
        $ordersCount = $this->tests->getOrdersCount();
        $paymentIntent = $this->tests->confirmCheckoutSession($order, "Subscription", "card", "California");
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Now lets create a subscription using the legacy method and associate it with $order

        $taxPercent = "8.25";
        $productTaxRate = null;
        $rates = $this->tests->stripe()->taxRates->all(['limit' => 00]);
        foreach ($rates->autoPagingIterator() as $rate)
        {
            if ($rate->percentage == $taxPercent && !$rate->inclusive)
            {
                $productTaxRate = $rate;
                break;
            }
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

        $customer = $this->tests->stripe()->customers->create([
            "name" => "Joyce Strother",
            "email" => "joyce@example.com"
        ]);

        $magentoProduct = $this->tests->helper()->loadProductBySku('simple-monthly-subscription-product');

        $metadata = [
            "Product ID" => $magentoProduct->getId(),
            "Customer ID" => "0",
            "Order #" => $order->getIncrementId(),
            "Module" => "Magento2 v2.6.1",
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

        $session = $this->tests->stripe()->checkout->sessions->create([
            "cancel_url" => "http://m2official.loc/stripe/payment/index/payment_method/checkout_card/",
            "payment_method_types" => [ "card"],
            "success_url" => "http://m2official.loc/stripe/payment/index/payment_method/checkout_card/",
            "client_reference_id" => $order->getIncrementId(),
            "metadata" => [
                "Order #" => $order->getIncrementId(),
                "Payment Method" => "Stripe Checkout"
            ],
            "locale" => "en",
            "line_items" => [
                [
                    "price_data" => [
                        "currency" => "USD",
                        "product_data" => [
                            "name" => "Simple Monthly Subscription",
                            "images" => [
                                "http://m2official.loc/static/webapi_rest/_view/en_GB/Magento_Catalog/images/product/placeholder/.jpg"
                            ],
                            "metadata" => [
                                "Type" => "Product",
                                "Product ID" => $magentoProduct->getId()
                            ]
                        ],
                        "unit_amount" => "1000",
                        "recurring" => [
                            "interval" => "month",
                            "interval_count" => "1"
                        ]
                    ],
                    "quantity" => "2",
                    "tax_rates" => [
                        $productTaxRate->id
                    ]
                ],
                [
                    "price_data" => [
                        "currency" => "USD",
                        "product_data" => [
                            "name" => "Shipping for subscription items",
                            "metadata" => [
                                "Type" => "Shipping"
                            ]
                        ],
                        "unit_amount" => "1000",
                        "recurring" => [
                            "interval" => "month",
                            "interval_count" => "1"
                        ]
                    ],
                    "quantity" => "1"
                ]
            ],
            "mode" => "subscription",
            "subscription_data" => [
                "metadata" => $metadata
            ],
            "customer" => $customer->id
        ]);

        $order->getPayment()->setAdditionalInformation('checkout_session_id', $session->id);

        $oldCustomerId = $paymentIntent->customer;
        $customerModel = $this->objectManager->get(\StripeIntegration\Payments\Model\StripeCustomer::class);
        $customerModel->load($oldCustomerId, 'stripe_id');
        $customerModel->setStripeId($customer->id);
        $customerModel->save();

        $paymentIntent = $this->tests->confirmCheckoutSession($order, "Subscription", "card", "California");

        $paymentIntent->description = "Order #" . $order->getIncrementId() . " by Jane Doe";
        $paymentIntent->save();

        // Done, now trigger a recurring order webhook event

        $customer = $this->tests->stripe()->customers->retrieve($customer->id);
        $subscription = $customer->subscriptions->data[0];
        $this->tests->event()->trigger("invoice.payment_succeeded", $subscription->latest_invoice, ['billing_reason' => 'subscription_cycle']);

        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());

        $this->tests->compare($order->getData(), [
            "tax_amount" => $newOrder->getTaxAmount(),
            "grand_total" => $newOrder->getGrandTotal(),
        ]);
    }
}
