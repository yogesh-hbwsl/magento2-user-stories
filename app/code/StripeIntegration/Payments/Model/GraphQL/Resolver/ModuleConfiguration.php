<?php

namespace StripeIntegration\Payments\Model\GraphQL\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

class ModuleConfiguration implements \Magento\Framework\GraphQl\Query\ResolverInterface
{
    private $api;

    private $json;

    public function __construct(
        \StripeIntegration\Payments\Api\Service $api,
        \Magento\Framework\Serialize\Serializer\Json $json
    ) {
        $this->api = $api;
        $this->json = $json;
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
            return $this->json->unserialize($this->api->getModuleConfiguration());
        }
        catch (\Exception $e)
        {
            throw new GraphQlInputException(__($e->getMessage()));
        }
    }
}
