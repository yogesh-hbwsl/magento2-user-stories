<?php

namespace StripeIntegration\Payments\Exception;

class SkipCaptureException extends \Exception
{
    public const INVALID_STATUS = 10;
    public const ORDERS_NOT_PROCESSED = 20;
    public const ZERO_AMOUNT = 30;
}
