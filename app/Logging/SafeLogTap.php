<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class SafeLogTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(new SafeLogProcessor());
    }
}
