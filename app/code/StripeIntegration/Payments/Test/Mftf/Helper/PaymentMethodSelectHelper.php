<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Mftf\Helper;

use Facebook\WebDriver\Remote\RemoteWebDriver as FacebookWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Magento\FunctionalTestingFramework\Helper\Helper;
use Magento\FunctionalTestingFramework\Module\MagentoWebDriver;

/**
 * Class for MFTF helpers for select payment option in select box
 */
class PaymentMethodSelectHelper extends Helper
{
    /**
     * Select payment method from select box in checkout page
     *
     * @param string $optionSelector
     * @param string $optionInput
     * @return void
     */
    public function selectPaymentMethodOption(
        string $optionSelector,
        string $optionInput
    ): void {
        try {
            /** @var MagentoWebDriver $magentoWebDriver */
            $magentoWebDriver = $this->getModule('\Magento\FunctionalTestingFramework\Module\MagentoWebDriver');
            /** @var \Facebook\WebDriver\Remote\RemoteWebDriver $webDriver */
            $webDriver = $magentoWebDriver->webDriver;
            $rows = $webDriver->findElements(WebDriverBy::cssSelector($optionSelector));
            if (!empty($rows)) {
                $rows[0]->click();
                $magentoWebDriver->selectOption($optionSelector, $optionInput);
            }
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }
    }
}
