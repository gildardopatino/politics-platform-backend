<?php

namespace App\Services\SMS;

interface SMSInterface
{
    public function send(string $to, string $message): bool;
}
