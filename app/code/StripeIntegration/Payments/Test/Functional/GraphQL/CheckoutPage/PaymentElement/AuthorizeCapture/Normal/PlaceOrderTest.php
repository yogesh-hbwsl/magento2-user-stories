<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\GraphQL\PaymentElement\EmbeddedFlow\AuthorizeCapture\Normal;

use Magento\Framework\Exception\AuthenticationException;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQlAbstract;

/**
 * Test for placing the guest order using stripe normal card
 */
class PlaceOrderTest extends GraphQlAbstract
{
    /** @var  CustomerTokenServiceInterface */
    private $customerTokenService;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->customerTokenService = $objectManager->get(CustomerTokenServiceInterface::class);
    }

    /**
     * Test guest order using stripe normal card
     *
     * @magentoDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/ApiKeysTest.php
     * @magentoApiDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     * @throws \Exception
     */
    public function testStripePlaceOrder()
    {
        $quantity = 1;
        $sku = 'simple_product';
        $guestEmail = 'guest@example.com';
        $cartId = $this->createEmptyCart();
        $this->addProductToCart($cartId, $quantity, $sku);
        $this->addGuestEmailToCart($cartId, $guestEmail);
        $this->setShippingAddress($cartId);
        $this->setBillingAddress($cartId);
        $this->setShippingMethod($cartId);
        $this->setPaymentMethod($cartId);
        $this->placeOrder($cartId);
    }

    /**
     * Create the empty cart
     *
     * @return string
     * @throws \Exception
     */
    private function createEmptyCart(): string
    {
        $query = <<<QUERY
mutation {
  createEmptyCart
}
QUERY;
        $response = $this->graphQlMutation($query);
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
     * @return void
     * @throws AuthenticationException
     */
    private function addProductToCart(string $cartId, float $quantity, string $sku): void
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
        $this->graphQlMutation($query);
    }

    /**
     * Add Guest email to the cart
     *
     * @param string $cartId
     * @param string $guestEmail
     * @throws \Exception
     */
    private function addGuestEmailToCart($cartId, $guestEmail)
    {
        $query = <<<QUERY
mutation {
  setGuestEmailOnCart(input: {
    cart_id: "$cartId"
    email: "$guestEmail"
  }) {
    cart {
      email
    }
  }
}
QUERY;
        $this->graphQlMutation($query);
    }

    /**
     * Set shipping address to the cart
     *
     * @param string $cartId
     * @throws \Exception
     */
    private function setShippingAddress($cartId)
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
        $this->graphQlMutation($query);
    }

    /**
     * Set billing address to the cart
     *
     * @param string $cartId
     * @throws \Exception
     */
    private function setBillingAddress($cartId)
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
        $this->graphQlMutation($query);
    }

    /**
     * Set Shipping method to the cart
     *
     * @param string $cartId
     * @throws \Exception
     */
    private function setShippingMethod($cartId)
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
        $this->graphQlMutation($query);
    }

    /**
     * Set Payment method to the cart - PaymentElement
     *
     * @param string $cartId
     * @throws \Exception
     */
    private function setPaymentMethod($cartId)
    {
        $query = <<<QUERY
mutation {
  setPaymentMethodOnCart(input: {
      cart_id: "{$cartId}"
      payment_method: {
        code: "stripe_payments"
        stripe_payments: {
          payment_element: true
          payment_method: "pm_card_visa"
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
        $this->graphQlMutation($query);
    }

    /**
     * Place the order
     *
     * @param string $cartId
     * @throws \Exception
     */
    private function placeOrder($cartId)
    {
        $query = <<<QUERY
mutation {
  placeOrder(input: {
    cart_id: "{$cartId}"
    }) {
    order {
      order_number
      client_secret
    }
  }
}
QUERY;
        $response = $this->graphQlMutation($query);
        self::assertArrayHasKey('placeOrder', $response);
        self::assertArrayHasKey('order', $response['placeOrder']);
        self::assertArrayHasKey('order_number', $response['placeOrder']['order']);
        self::assertNotEmpty($response['placeOrder']['order']['order_number']);
        self::assertNotEmpty($response['placeOrder']['order']['client_secret']);
    }
}
