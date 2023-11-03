<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Functional\AccountPage;

use Magento\Framework\Exception\AuthenticationException;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQlAbstract;

/**
 * Test for saved payment methods - Stripe
 */
class MySavedPaymentMethodTest extends GraphQlAbstract
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
     * Test saved payment method - Add, List, Delete
     *
     * @magentoDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/ApiKeysTest.php
     * @magentoApiDataFixture StripeIntegration_Payments::Test/Functional/GraphQL/_files/saved_payment_method_customer.php
     * @magentoConfigFixture default_store payment/stripe_payments/active 1
     * @magentoConfigFixture default_store payment/stripe_payments/payment_flow 0
     * @throws \Exception
     */
    public function testSavedPaymentMethod()
    {
        $paymentObjectId = $this->addPaymentMethod();
        $this->listPaymentMethod();
        $this->deletePaymentMethod($paymentObjectId);
    }

    /**
     * Add Payment method
     *
     * @throws AuthenticationException
     */
    private function addPaymentMethod()
    {
        $query = <<<QUERY
mutation {
  addStripePaymentMethod(
    input: {
      payment_method: "pm_card_visa"
    }
  ) {
    id
    created
    type
    fingerprint
    label
    icon
    cvc
    brand
    exp_month
    exp_year
  }
}
QUERY;
        $response = $this->graphQlMutation($query, [], '', $this->getHeaderMap());
        self::assertArrayHasKey('addStripePaymentMethod', $response);
        self::assertNotEmpty($response['addStripePaymentMethod']);
        self::assertNotEmpty($response['addStripePaymentMethod']['id']);
        return $response['addStripePaymentMethod']['id'];
    }

    /**
     * List Payment method
     *
     * @throws AuthenticationException
     */
    private function listPaymentMethod()
    {
        $query = <<<QUERY
mutation {
  listStripePaymentMethods {
    id
    created
    type
    fingerprint
    label
    icon
    cvc
    brand
    exp_month
    exp_year
  }
}
QUERY;
        $response = $this->graphQlMutation($query, [], '', $this->getHeaderMap());
        self::assertArrayHasKey('listStripePaymentMethods', $response);
        self::assertNotEmpty($response['listStripePaymentMethods']);
        self::assertCount(1, $response['listStripePaymentMethods']);
    }

    /**
     * Delete payment method
     *
     * @param string $paymentObjectId
     * @throws AuthenticationException
     */
    private function deletePaymentMethod($paymentObjectId)
    {
        $query = <<<QUERY
mutation {
  deleteStripePaymentMethod(
    input: {
      payment_method: "{$paymentObjectId}"
      fingerprint: null
    }
  )
}
QUERY;
        $response = $this->graphQlMutation($query, [], '', $this->getHeaderMap());
        self::assertArrayHasKey('deleteStripePaymentMethod', $response);
        self::assertNotEmpty($response['deleteStripePaymentMethod']);
    }

    /**
     * Create the customer
     *
     * @param string $username
     * @param string $password
     * @return array
     * @throws AuthenticationException
     */
    private function getHeaderMap(string $username = 'graphql@example.com', string $password = 'password'): array
    {
        $customerToken = $this->customerTokenService->createCustomerAccessToken($username, $password);

        return ['Store' => $this->storeCode, 'Authorization' => 'Bearer ' . $customerToken];
    }
}
