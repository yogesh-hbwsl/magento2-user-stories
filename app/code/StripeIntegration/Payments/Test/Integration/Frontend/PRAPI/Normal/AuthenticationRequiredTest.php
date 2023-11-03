<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\PRAPI\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class AuthenticationRequiredTest extends \PHPUnit\Framework\TestCase
{
    private $apiService;
    private $helper;
    private $objectManager;
    private $request;
    private $session;
    private $stripeConfig;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->request = $this->objectManager->get(\Magento\Framework\App\Request\Http::class);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->apiService = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->session = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function test3DS()
    {
        $product = $this->helper->loadProductBySku("simple-product");
        $request = http_build_query([
            "product" => $product->getId(),
            "related_product" => "",
            "qty" => 1
        ]);
        $result = $this->apiService->addtocart($request);
        $this->assertNotEmpty($result);

        $data = json_decode($result, true);
        $this->assertNotEmpty($data["results"]);

        $address = $this->tests->address()->getPRAPIFormat("NewYork");
        $payerDetails = [
            'email' => $address["email"],
            'name' => $address["recipient"],
            'phone' => $address["phone"]
        ];

        $result = $this->apiService->estimate_cart($address);
        $this->assertNotEmpty($result);

        $data = json_decode($result, true);
        $this->assertNotEmpty($data["results"]);

        $selectedShippingMethod = $data["results"][0];
        $result = $this->apiService->apply_shipping($address, $selectedShippingMethod["id"]);
        $this->assertNotEmpty($result);

        $data = json_decode($result, true);
        $this->assertNotEmpty($data["results"]["displayItems"]);

        $stripe = $this->stripeConfig->getStripeClient();
        $paymentMethod = $stripe->paymentMethods->create([
          'type' => 'card',
          'card' => [
            'number' => '4000000000003063',
            'exp_month' => 7,
            'exp_year' => date("Y", time()) + 1,
            'cvc' => '314',
          ],
          'billing_details' => $this->tests->address()->getStripeFormat("NewYork")
        ]);
        $this->assertNotEmpty($paymentMethod);
        $this->assertNotEmpty($paymentMethod->id);

        $result = [
            "payerEmail" => $payerDetails["email"],
            "payerName" => $payerDetails["name"],
            "payerPhone" => $payerDetails["phone"],
            "shippingAddress" => $address,
            "shippingOption" => $selectedShippingMethod,
            "paymentMethod" => $paymentMethod
        ];

        try
        {
            $result = $this->apiService->place_order($result, "product");
        }
        catch (\Exception $e)
        {
            $this->assertStringContainsString("Authentication Required: pi_", $e->getMessage());
        }
    }
}
