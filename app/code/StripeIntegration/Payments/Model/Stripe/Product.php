<?php

namespace StripeIntegration\Payments\Model\Stripe;

class Product extends StripeObject
{
    protected $objectSpace = 'products';

    public function fromData($id, $name, $metadata = null)
    {
        $data = [
            'name' => $name
        ];

        if (!empty($metadata))
        {
            $data['metadata'] = $metadata;
        }

        $this->upsert($id, $data);

        if (!$this->object)
            throw new \Magento\Framework\Exception\LocalizedException(__("The product could not be created in Stripe: %1", $this->lastError));

        return $this;
    }

    public function fromOrderItem($orderItem)
    {
        if ($orderItem->getParentItem() && $orderItem->getParentItem()->getName() && $orderItem->getParentItem()->getProductId())
        {
            $name = $orderItem->getParentItem()->getName();
            $productId = $orderItem->getParentItem()->getProductId();
        }
        else
        {
            $name = $orderItem->getName();
            $productId = $orderItem->getProductId();
        }

        $data = [
            'name' => $name
        ];

        $this->upsert($productId, $data);

        if (!$this->object)
            throw new \Magento\Framework\Exception\LocalizedException(__("The product \"%1\" could not be created in Stripe: %2", $orderItem->getName(), $this->lastError));

        return $this;
    }

    public function fromQuoteItem($quoteItem)
    {
        return $this->fromOrderItem($quoteItem);
    }
}
