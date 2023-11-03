<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

use Magento\Framework\Exception\AuthenticationException;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQlAbstract;

/**
 * Test for placing the guest order using stripe 3DS card
 */
class ThreeDSPlaceOrderTest extends GraphQlAbstract
{
    /** @var  CustomerTokenServiceInterface */
    private $customerTokenService;

    private $storeCode = 'default';

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->customerTokenService = $objectManager->get(CustomerTokenServiceInterface::class);
    }

    /**
     * Test guest order using stripe 3DS card
     *
     * @magentoDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/ApiKeysTest.php
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     * @throws \Exception
     */
    public function testStripePlaceOrder()
    {
        $quantity = 1;
        $sku = 'simple_product';
        $customerToken = $this->getHeaderMap();
        $cartId = $this->createEmptyCart($customerToken);
        $this->addProductToCart($cartId, $quantity, $sku, $customerToken);
        $this->setShippingAddress($cartId, $customerToken);
        $this->setBillingAddress($cartId, $customerToken);
        $this->setShippingMethod($cartId, $customerToken);
        $this->setPaymentMethod($cartId, $customerToken);
        $this->placeOrder($cartId, $customerToken);
    }

    /**
     * Create the empty cart
     *
     * @param array $customerToken
     * @return string
     * @throws \Exception
     */
    private function createEmptyCart($customerToken): string
    {
        $query = <<<QUERY
mutation {
  createEmptyCart
}
QUERY;
        $response = $this->graphQlMutation($query, [], '', $customerToken);
        self::assertArrayHasKey('createEmptyCart', $response);
        self::assertNotEmpty($response['createEmptyCart']);
        return $response['createEmptyCart'];
    }

    /**
     * Add product to the cart
     *
     * @param string $cartId
     * @param float $quantity
     * @param string $sku
     * @param array $customerToken
     * @return void
     * @throws \Exception
     */
    private function addProductToCart(string $cartId, float $quantity, string $sku, $customerToken): void
    {
        $query = <<<QUERY
mutation {
  addSimpleProductsToCart(
    input: {
      cart_id: "{$cartId}"
      cart_items: [
        {
          data: {
            quantity: {$quantity}
            sku: "{$sku}"
          }
        }
      ]
    }
  ) {
    cart {
      items {
        quantity
        product {
          sku
        }
      }
    }
  }
}
QUERY;
        $this->graphQlMutation($query, [], '', $customerToken);
    }

    /**
     * Set shipping address to the cart
     *
     * @param string $cartId
     * @param array $customerToken
     * @throws \Exception
     */
    private function setShippingAddress($cartId, $customerToken)
    {
        $query = <<<QUERY
mutation {
  setShippingAddressesOnCart(
    input: {
      cart_id: "{$cartId}"
      shipping_addresses: [
        {
          address: {
            firstname: "John"
            lastname: "Doe"
            company: "Company Name"
            street: ["3320 N Crescent Dr", "Beverly Hills"]
            city: "Los Angeles"
            region: "CA"
            region_id: 12
            postcode: "90210"
            country_code: "US"
            telephone: "123-456-0000"
            save_in_address_book: false
          }
        }
      ]
    }
  ) {
    cart {
      shipping_addresses {
        firstname
        lastname
        company
        street
        city
        region {
          code
          label
        }
        postcode
        telephone
        country {
          code
          label
        }
        available_shipping_methods{
          carrier_code
          carrier_title
          method_code
          method_title
        }
      }
    }
  }
}
QUERY;
        $this->graphQlMutation($query, [], '', $customerToken);
    }

    /**
     * Set billing address to the cart
     *
     * @param string $cartId
     * @param array $customerToken
     * @throws \Exception
     */
    private function setBillingAddress($cartId, $customerToken)
    {
        $query = <<<QUERY
mutation {
  setBillingAddressOnCart(
    input: {
      cart_id: "{$cartId}"
      billing_address: {
        address: {
          firstname: "John"
          lastname: "Doe"
          company: "Company Name"
          street: ["64 Strawberry Dr", "Beverly Hills"]
          city: "Los Angeles"
          region: "CA"
          region_id: 12
          postcode: "90210"
          country_code: "US"
          telephone: "123-456-0000"
          save_in_address_book: true
        }
      }
    }
  ) {
    cart {
      billing_address {
        firstname
        lastname
        company
        street
        city
        region{
          code
          label
        }
        postcode
        telephone
        country {
          code
          label
        }
      }
    }
  }
}
QUERY;
        $this->graphQlMutation($query, [], '', $customerToken);
    }

    /**
     * Set Shipping method to the cart
     *
     * @param string $cartId
     * @param array $customerToken
     * @throws \Exception
     */
    private function setShippingMethod($cartId, $customerToken)
    {
        $query = <<<QUERY
mutation {
  setShippingMethodsOnCart(input: {
    cart_id: "{$cartId}"
    shipping_methods: [
      {
        carrier_code: "flatrate"
        method_code: "flatrate"
      }
    ]
  }) {
    cart {
      shipping_addresses {
        selected_shipping_method {
          carrier_code
          method_code
          carrier_title
          method_title
        }
      }
    }
  }
}
QUERY;
        $this->graphQlMutation($query, [], '', $customerToken);
    }

    /**
     * Set Stripe 3DS card - Payment method to the cart
     *
     * @param string $cartId
     * @param array $customerToken
     * @throws \Exception
     */
    private function setPaymentMethod($cartId, $customerToken)
    {
        $query = <<<QUERY
mutation {
  setPaymentMethodOnCart(input: {
      cart_id: "{$cartId}"
      payment_method: {
        code: "stripe_payments"
        stripe_payments: {
          cc_stripejs_token: "pm_card_authenticationRequired"
          save_payment_method: true
        }
      }
  }) {
    cart {
      selected_payment_method {
        code
      }
    }
  }
}
QUERY;
        $this->graphQlMutation($query, [], '', $customerToken);
    }

    /**
     * Place the order and verify if the exception message is matches
     *
     * @param string $cartId
     * @param array $customerToken
     * @throws \Exception
     */
    private function placeOrder($cartId, $customerToken)
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Authentication Required:*/');
        $query = <<<QUERY
mutation {
  placeOrder(input: {
    cart_id: "{$cartId}"
    }) {
    order {
      order_number
    }
  }
}
QUERY;
        $this->graphQlMutation($query, [], '', $customerToken);
    }

    /**
     * Create the customer
     *
     * @param string $username
     * @param string $password
     * @return array
     * @throws AuthenticationException
     */
    private function getHeaderMap(string $username = 'customer@example.com', string $password = 'password'): array
    {
        $customerToken = $this->customerTokenService->createCustomerAccessToken($username, $password);

        return ['Store' => $this->storeCode, 'Authorization' => 'Bearer ' . $customerToken];
    }
}
