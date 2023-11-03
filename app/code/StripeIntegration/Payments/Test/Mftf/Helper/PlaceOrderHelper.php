<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Test\Mftf\Helper;

use Facebook\WebDriver\Remote\RemoteWebDriver as FacebookWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Magento\FunctionalTestingFramework\Helper\Helper;
use Magento\FunctionalTestingFramework\Module\MagentoWebDriver;

class PlaceOrderHelper extends \Magento\FunctionalTestingFramework\Helper\Helper
{
    // Clicks the place order button, but does not wait for the DOM ready event because a redirect is expected.
    // Speeds up tests for redirect based payment methods
    public function placeOrderRedirect($buttonSelector)
    {
        try
        {
            $magentoWebDriver = $this->getModule('\Magento\FunctionalTestingFramework\Module\MagentoWebDriver');
            $webDriver = $magentoWebDriver->webDriver;

            $placeOrderButton = $webDriver->findElements(WebDriverBy::cssSelector($buttonSelector));
            if (!empty($placeOrderButton))
                $placeOrderButton[0]->click(); // $magentoWebDriver->click($buttonSelector);
            else
                throw new \Exception("Place Order button not found: $buttonSelector");
        }
        catch (\Exception $e)
        {
            $this->fail($e->getMessage());
        }
    }
}
