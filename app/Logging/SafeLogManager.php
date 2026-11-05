<?php

namespace App\Logging;

use Illuminate\Log\LogManager;

/** Apply the same policy when Laravel falls back to its emergency file logger. */
class SafeLogManager extends LogManager
{
    protected function createEmergencyLogger()
    {
        $logger = parent::createEmergencyLogger();
        $logger->getLogger()->pushProcessor(new SafeLogProcessor());

        return $logger;
    }
}
