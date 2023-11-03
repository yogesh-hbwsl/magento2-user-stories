<?php

namespace StripeIntegration\Payments\Model\GraphQL\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;

class ListStripePaymentMethods implements \Magento\Framework\GraphQl\Query\ResolverInterface
{
    private $api;
    private $serializer;

    public function __construct(
        \StripeIntegration\Payments\Api\Service $api,
        \Magento\Framework\Serialize\SerializerInterface $serializer
    ) {
        $this->api = $api;
        $this->serializer = $serializer;
    }

    public function resolve(
        \Magento\Framework\GraphQl\Config\Element\Field $field,
        $context,
        \Magento\Framework\GraphQl\Schema\Type\ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        try
        {
            $result = $this->api->list_payment_methods();
            return $this->serializer->unserialize($result);
        }
        catch (\Exception $e)
        {
            throw new GraphQlInputException(__($e->getMessage()));
        }
    }
}
