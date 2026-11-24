<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class TelegramRequestException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(500, $message);
    }
}
