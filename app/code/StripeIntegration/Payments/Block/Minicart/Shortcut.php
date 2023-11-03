<?php

namespace StripeIntegration\Payments\Block\Minicart;

use StripeIntegration\Payments\Block\Button as StripeButton;
use Magento\Catalog\Block\ShortcutInterface;

class Shortcut extends StripeButton implements ShortcutInterface
{
    const ALIAS_ELEMENT_INDEX = 'alias';

    /**
     * Path to template file in theme.
     *
     * @var string
     */
    protected $_template = 'StripeIntegration_Payments::express/minicart_button.phtml';

    /**
     * @var bool
     */
    private $isMiniCart = false;

    private $isShoppingCart;

    /**
     * Get shortcut alias
     *
     * @return string
     */
    public function getAlias()
    {
        return $this->getData(self::ALIAS_ELEMENT_INDEX);
    }

    /**
     * @param bool $isCatalog
     * @return $this
     */
    public function setIsInCatalogProduct($isCatalog)
    {
        $this->isMiniCart = !$isCatalog;

        return $this;
    }

    public function setIsShoppingCart($isShoppingCart)
    {
        $this->isShoppingCart = $isShoppingCart;

        if ($isShoppingCart)
            $this->_template = 'StripeIntegration_Payments::express/cart_button.phtml';
        else
            $this->_template = 'StripeIntegration_Payments::express/minicart_button.phtml';
    }

    /**
     * Is Should Rendered
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function shouldRender()
    {
        if ($this->getIsCart())
            return true;

        return $this->isEnabled("minicart") && $this->isMiniCart;
    }

    /**
     * Render the block if needed
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function _toHtml()
    {
        if (!$this->shouldRender())
            return '';

        return parent::_toHtml();
    }
}
