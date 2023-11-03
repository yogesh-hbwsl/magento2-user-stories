<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

class AddExpressButton implements ObserverInterface
{

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Catalog\Block\ShortcutButtons $shortcutButtons */
        $shortcutButtons = $observer->getEvent()->getContainer();

        /** @var \Magento\Framework\View\Element\Template $shortcut */
        $shortcut = $shortcutButtons->getLayout()->createBlock(
            \StripeIntegration\Payments\Block\Minicart\Shortcut::class,
            '',
            []
        );

        $shortcut->setIsInCatalogProduct(
            $observer->getEvent()->getIsCatalogProduct()
        )->setShowOrPosition(
            $observer->getEvent()->getOrPosition()
        );

        $shortcut->setIsShoppingCart($observer->getEvent()->getIsShoppingCart());

        $shortcut->setIsCart(get_class($shortcutButtons) == \Magento\Checkout\Block\QuoteShortcutButtons::class);

        $shortcutButtons->addShortcut($shortcut);
    }
}
