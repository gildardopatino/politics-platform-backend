<?php

namespace App\Services\SMS;

use Illuminate\Support\Facades\Log;

class LogSMS implements SMSInterface
{
    public function send(string $to, string $message): bool
    {
        Log::info('SMS enviado', [
            'to' => $to,
            'message' => $message,
            'timestamp' => now(),
        ]);

        return true;
    }
}
