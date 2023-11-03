<?php

namespace StripeIntegration\Payments\Test\Integration\Helper;

class MultishippingQuote extends Quote
{
    protected $checkout = null;
    protected $shippingInfo = [];

    public function __construct()
    {
        parent::__construct();

        $this->checkout = $this->objectManager->get(\Magento\Multishipping\Model\Checkout\Type\Multishipping::class);
    }

    public function create()
    {
        parent::create();
        $this->quote->setIsMultiShipping(true);
        return $this;
    }

    public function setCart($identifier)
    {
        return $this->setCartWithQtys($identifier, 1);
    }

    public function setCartWithQtys($identifier, $qty)
    {
        $this->quote->removeAllItems();
        $this->checkout->setQuote($this->quote);
        $this->setCustomer('LoggedIn');
        $addresses = $this->customer->getAddresses();
        $customerSession = $this->checkout->getCustomerSession();
        $customerSession->loginById($this->customer->getId());

        switch ($identifier)
        {
            case 'Normal':
                $product = $this->productRepository->get('simple-product');
                $addressIds = [];
                $qtyToAdd = $qty;
                foreach ($addresses as $address)
                {
                    $this->addProduct('simple-product', $qtyToAdd)->save();
                    $qtyToAdd += $qty;
                    $addressIds[] = $address->getId();

                    if (count($addressIds) >= 2)
                        break;
                }
                $mod = count($addressIds);

                $quoteItemIds = [];
                $i = 0;
                $shippingInfo = [];
                foreach ($this->quote->getAllVisibleItems() as $quoteItem)
                {
                    $quoteItemIds[] = $quoteItem->getId();

                    for ($count = $quoteItem->getQtyToAdd(); $count > 0; $count -= $qty)
                    {
                        $addressIndex = ($i++ % $mod);
                        $shippingInfo[] = [
                            $quoteItem->getId() => [
                                'qty' => $qty,
                                'address' => $addressIds[$addressIndex]
                            ]
                        ];
                    }
                }
                $this->checkout->setShippingItemsInformation($shippingInfo);

                $methods = [];
                $addresses = $this->quote->getAllShippingAddresses();
                foreach ($addresses as $address)
                {
                    $methods[$address->getId()] = 'flatrate_flatrate';
                }
                $this->checkout->setShippingMethods($methods);

                break;

            default:
                throw new \Exception("No such cart ID");
        }

        return $this;
    }

    public function setShippingAddress($identifier)
    {

    }

    public function setShippingMethod($identifier)
    {

    }

    public function setPaymentMethod($identifier)
    {
        $data = $this->getPaymentMethodData($identifier);

        if (empty($data['additional_data']['payment_method']))
            throw new \Exception("Can't find payment method $identifier");

        $multishippingData = [
            'method' => 'stripe_payments',
            'additional_data' => [
                "cc_stripejs_token" => $data['additional_data']['payment_method']
            ]
        ];

        $this->quote->getPayment()->importData($multishippingData);

        return $this->save();
    }
}
