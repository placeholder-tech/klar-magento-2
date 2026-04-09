<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Logger\Handler;

use Magento\Framework\Logger\Handler\Base as BaseHandler;
use Monolog\Logger as MonologLogger;

class KlarHandler extends BaseHandler
{
    /**
     * Logging level.
     *
     * @var int
     */
    protected $loggerType = MonologLogger::DEBUG;

    /**
     * Log file name.
     *
     * @var string
     */
    protected $fileName = '/var/log/klar/klar.log';
}
