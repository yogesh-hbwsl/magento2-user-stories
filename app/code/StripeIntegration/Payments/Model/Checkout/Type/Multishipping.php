<?php

namespace StripeIntegration\Payments\Model\Checkout\Type;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\ObjectManager;
use Magento\Directory\Model\AllowedCountries;
use Psr\Log\LoggerInterface;

class Multishipping extends \Magento\Multishipping\Model\Checkout\Type\Multishipping
{
    protected $placeOrderFactory = null;
    protected $logger = null;
    protected $eventManager = null;

    private $session;
    private $checkoutSession;

    protected function _construct()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->placeOrderFactory = $objectManager->get(\Magento\Multishipping\Model\Checkout\Type\Multishipping\PlaceOrderFactory::class);
        $this->logger = $objectManager->get(\Psr\Log\LoggerInterface::class);
        $this->session = $objectManager->get(\Magento\Framework\Session\Generic::class);
        $this->checkoutSession = $objectManager->get(\Magento\Checkout\Model\Session::class);
        $this->eventManager = $objectManager->get(\Magento\Framework\Event\ManagerInterface::class);
    }

    public function createOrders()
    {
        $this->_construct();

        $quote = $this->getQuote();
        $orders = [];

        $this->_validate();

        $shippingAddresses = $quote->getAllShippingAddresses();
        if ($quote->hasVirtualItems())
            $shippingAddresses[] = $quote->getBillingAddress();

        foreach ($shippingAddresses as $address)
        {
            $order = $this->_prepareOrder($address);

            $orders[] = $order;
            $this->eventManager->dispatch(
                'checkout_type_multishipping_create_orders_single',
                ['order' => $order, 'address' => $address, 'quote' => $quote]
            );
        }

        $paymentProviderCode = $quote->getPayment()->getMethod();
        $placeOrderService = $this->placeOrderFactory->create($paymentProviderCode);
        $exceptionList = $placeOrderService->place($orders);

        foreach ($exceptionList as $exception)
            $this->logger->critical($exception);

        return [
            "orders" => $orders,
            "exceptionList" => $exceptionList
        ];
    }

    public function getAddressErrors($quote, $successfulOrders, $failedOrders, $exceptionList)
    {
        $shippingAddresses = $quote->getAllShippingAddresses();
        if ($quote->hasVirtualItems())
            $shippingAddresses[] = $quote->getBillingAddress();

        $addressErrors = [];
        if (!empty($failedOrders))
        {
            $addressErrors = $this->getQuoteAddressErrors(
                $failedOrders,
                $shippingAddresses,
                $exceptionList
            );
        }

        return $addressErrors;
    }
    public function removeSuccessfulOrdersFromQuote($quote, $successfulOrders)
    {
        $shippingAddresses = $quote->getAllShippingAddresses();
        if ($quote->hasVirtualItems())
            $shippingAddresses[] = $quote->getBillingAddress();

        $placedAddressItems = [];
        foreach ($successfulOrders as $order)
            $placedAddressItems = $this->getPlacedAddressItems($order);

        if (!empty($placedAddressItems))
            $this->removePlacedItemsFromQuote($shippingAddresses, $placedAddressItems);
    }

    public function deactivateQuote($quote)
    {
        $this->_construct();

        $this->checkoutSession->setLastQuoteId($quote->getId());
        $quote->setIsActive(false);
        $this->quoteRepository->save($quote);
    }

    public function setResultsPageData($quote, $successfulOrders, $failedOrders, $exceptionList)
    {
        $shippingAddresses = $quote->getAllShippingAddresses();
        if ($quote->hasVirtualItems())
            $shippingAddresses[] = $quote->getBillingAddress();

        $successfulOrderIds = [];
        foreach ($successfulOrders as $order)
            $successfulOrderIds[$order->getId()] = $order->getIncrementId();

        $this->session->setOrderIds($successfulOrderIds);

        $addressErrors = [];
        if (!empty($failedOrders))
        {
            $addressErrors = $this->getQuoteAddressErrors($failedOrders, $shippingAddresses, $exceptionList);
            $this->session->setAddressErrors($addressErrors);
        }
    }

    /**
     * Remove successfully placed items from quote.
     *
     * @param \Magento\Quote\Model\Quote\Address[] $shippingAddresses
     * @param int[] $placedAddressItems
     * @return void
     */
    private function removePlacedItemsFromQuote(array $shippingAddresses, array $placedAddressItems)
    {
        foreach ($shippingAddresses as $address) {
            foreach ($address->getAllItems() as $addressItem) {
                if (in_array($addressItem->getQuoteItemId(), $placedAddressItems)) {

                    if ($addressItem->getProduct()->getIsVirtual()) {
                        $addressItem->isDeleted(true);
                    } else {
                        $address->isDeleted(true);
                    }

                    $this->decreaseQuoteItemQty($addressItem->getQuoteItemId(), $addressItem->getQty());
                }
            }
        }
        $this->save();
    }

    /**
     * Decrease quote item quantity.
     *
     * @param int $quoteItemId
     * @param int $qty
     * @return void
     */
    private function decreaseQuoteItemQty(int $quoteItemId, int $qty)
    {
        $quoteItem = $this->getQuote()->getItemById($quoteItemId);
        if ($quoteItem) {
            $newItemQty = $quoteItem->getQty() - $qty;
            if ($newItemQty > 0) {
                $quoteItem->setQty($newItemQty);
            } else {
                $this->getQuote()->removeItem($quoteItem->getId());
                $this->getQuote()->setIsMultiShipping(1);
            }
        }
    }

    /**
     * Returns quote address id that was assigned to order.
     *
     * @param OrderInterface $order
     * @param \Magento\Quote\Model\Quote\Address[] $addresses
     *
     * @return int
     * @throws NotFoundException
     */
    private function searchQuoteAddressId(OrderInterface $order, array $addresses): int
    {
        $items = $order->getItems();
        $item = array_pop($items);
        foreach ($addresses as $address) {
            foreach ($address->getAllItems() as $addressItem) {
                if ($addressItem->getQuoteItemId() == $item->getQuoteItemId()) {
                    return (int)$address->getId();
                }
            }
        }

        throw new NotFoundException(__('Quote address for failed order ID "%1" not found.', $order->getEntityId()));
    }

    /**
     * Get quote address errors.
     *
     * @param OrderInterface[] $orders
     * @param \Magento\Quote\Model\Quote\Address[] $addresses
     * @param \Exception[] $exceptionList
     * @return string[]
     * @throws NotFoundException
     */
    private function getQuoteAddressErrors(array $orders, array $addresses, array $exceptionList): array
    {
        $addressErrors = [];
        foreach ($orders as $failedOrder) {
            if (!isset($exceptionList[$failedOrder->getIncrementId()])) {
                throw new NotFoundException(__('Exception for failed order not found.'));
            }
            $addressId = $this->searchQuoteAddressId($failedOrder, $addresses);
            $addressErrors[$addressId] = $exceptionList[$failedOrder->getIncrementId()]->getMessage();
        }

        return $addressErrors;
    }

    /**
     * Returns placed address items
     *
     * @param OrderInterface $order
     * @return array
     */
    private function getPlacedAddressItems(OrderInterface $order): array
    {
        $placedAddressItems = [];

        $quoteItems = $this->getQuoteAddressItems($order);
        if (empty($quoteItems))
            return $placedAddressItems;

        foreach ($quoteItems as $key => $quoteAddressItem) {
            $placedAddressItems[$key] = $quoteAddressItem;
        }

        return $placedAddressItems;
    }

    /**
     * Returns quote address item id.
     *
     * @param OrderInterface $order
     * @return array
     */
    private function getQuoteAddressItems(OrderInterface $order): array
    {
        $placedAddressItems = [];
        foreach ($order->getItems() as $orderItem) {
            $placedAddressItems[] = $orderItem->getQuoteItemId();
        }

        return $placedAddressItems;
    }
}
