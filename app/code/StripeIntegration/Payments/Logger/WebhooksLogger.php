<?php

namespace StripeIntegration\Payments\Logger;

class WebhooksLogger extends \Monolog\Logger
{
    public function __construct(
        $name = 'WebhooksLogger',
        array $handlers = [],
        array $processors = []
    )
    {
        parent::__construct($name, $handlers, $processors);
    }
}
