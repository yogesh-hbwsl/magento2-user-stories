<?php

namespace StripeIntegration\Payments\Logger;

use Monolog\Logger;

class Handler extends \Magento\Framework\Logger\Handler\Base
{
    /**
     * Logging level
     * @var int
     */
    protected $loggerType = Logger::INFO;
    public $filePath;

    public function __construct(
        \Magento\Framework\Filesystem\DriverInterface $filesystem,
        \Magento\Framework\App\Filesystem\DirectoryList $dir
    ) {
        $ds = DIRECTORY_SEPARATOR;
        $this->filePath = $dir->getPath('log') . $ds . 'stripe_payments_webhooks.log';

        parent::__construct($filesystem, $this->filePath);
    }

    public function exists()
    {
        return file_exists($this->filePath);
    }
}
