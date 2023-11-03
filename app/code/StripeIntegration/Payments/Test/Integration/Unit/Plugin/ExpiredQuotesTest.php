<?php

namespace StripeIntegration\Payments\Test\Integration\Unit\Plugin;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ExpiredQuotesTest extends \PHPUnit\Framework\TestCase
{
    private $expiredQuotesCollection;
    private $objectManager;
    private $quote;
    private $quoteRepository;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->expiredQuotesCollection = $this->objectManager->get(\Magento\Sales\Model\ResourceModel\Collection\ExpiredQuotesCollection::class);
        $this->quoteRepository = $this->objectManager->create(\Magento\Quote\Api\CartRepositoryInterface::class);
    }

    /**
     * @magentoConfigFixture current_store checkout/cart/delete_quote_after -1
     */
    public function testRecurringOrderQuotes()
    {
        $quote = $this->quote->create()->getQuote();
        $this->quoteRepository->save($quote);

        $this->assertNotEmpty($quote->getId());

        $expiredQuotes = $this->expiredQuotesCollection->getExpiredQuotes($this->quote->getStore());
        $this->assertCount(1, $expiredQuotes);

        $quote->setIsUsedForRecurringOrders(true);
        $this->quoteRepository->save($quote);

        $expiredQuotes = $this->expiredQuotesCollection->getExpiredQuotes($this->quote->getStore());
        $this->assertCount(0, $expiredQuotes);
    }
}
