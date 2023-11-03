<?php

namespace StripeIntegration\Payments\Helper;

use Psr\Log\LoggerInterface;

class Logger
{
    static $logger = null;

    public static function getPrintableObject($obj)
    {
        if (!Logger::$logger)
            Logger::$logger = \Magento\Framework\App\ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class);

        if (is_object($obj))
        {
            if (method_exists($obj, 'debug'))
                $data = $obj->debug();
            else if (method_exists($obj, 'getData'))
                $data = $obj->getData();
            else
                $data = $obj;
        }
        else if (is_array($obj))
            $data = print_r($obj, true);
        else
            $data = $obj;

        return $data;
    }
    public static function debug($obj)
    {
        $data = Logger::getPrintableObject($obj);
        Logger::$logger->addDebug(print_r($data, true));
    }

    public static function log($obj)
    {
        try
        {
            $data = Logger::getPrintableObject($obj);

            if (method_exists(Logger::$logger, 'addInfo'))
                Logger::$logger->addInfo($data); // Magento 2.4.1 and older
            else
                Logger::$logger->error($data); // Magento 2.4.2 and newer
        }
        catch (\Exception $e)
        {
            // Errors cannot be logged...
        }
    }

    public static function logInfo($obj)
    {
        try
        {
            $data = Logger::getPrintableObject($obj);

            if (method_exists(Logger::$logger, 'addInfo'))
                Logger::$logger->addInfo($data); // Magento 2.4.1 and older
            else
                Logger::$logger->info($data); // Magento 2.4.2 and newer
        }
        catch (\Exception $e)
        {
            // Errors cannot be logged...
        }
    }

    public static function backtrace()
    {
        $e = new \Exception();
        $trace = explode("\n", $e->getTraceAsString());

        array_pop($trace); // remove {main}
        array_shift($trace); // remove call to this method

        self::log("\n\t" . implode("\n\t", $trace));
    }
}
