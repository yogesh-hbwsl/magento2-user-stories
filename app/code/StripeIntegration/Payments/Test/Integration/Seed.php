<?php

namespace StripeIntegration\Payments\Test\Integration;

class Seed extends \PHPUnit\Framework\TestCase
{
    /**
     * @magentoAppIsolation disabled
     * @magentoDbIsolation disabled
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Enable.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeys.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Taxes.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ExchangeRates.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Products.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Customer.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/SecondStore.php
     */
    public function testDatabaseSetup()
    {
        // Run this test only once to seed the database with products, taxes etc
    }
}
