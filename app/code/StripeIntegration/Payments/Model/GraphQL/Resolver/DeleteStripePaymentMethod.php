<?php

namespace StripeIntegration\Payments\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

class DeleteStripePaymentMethod implements \Magento\Framework\GraphQl\Query\ResolverInterface
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
        if (empty($args['input']['payment_method']))
            throw new GraphQlInputException(__("Please specify a payment method ID."));

        try
        {
            $fingerprint = null;
            if (!empty($args['input']['fingerprint']))
            {
                $fingerprint = $args['input']['fingerprint'];
            }

            return $this->api->delete_payment_method($args['input']['payment_method'], $fingerprint);
        }
        catch (\Exception $e)
        {
            throw new GraphQlInputException(__($e->getMessage()));
        }
    }
}
