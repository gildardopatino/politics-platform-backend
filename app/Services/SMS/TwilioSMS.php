<?php

namespace App\Services\SMS;

use Illuminate\Support\Facades\Http;

class TwilioSMS implements SMSInterface
{
    protected string $accountSid;
    protected string $authToken;
    protected string $fromNumber;

    public function __construct()
    {
        $this->accountSid = config('services.twilio.account_sid');
        $this->authToken = config('services.twilio.auth_token');
        $this->fromNumber = config('services.twilio.from');
    }

    public function send(string $to, string $message): bool
    {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";

        $response = Http::asForm()
            ->withBasicAuth($this->accountSid, $this->authToken)
            ->post($url, [
                'From' => $this->fromNumber,
                'To' => $to,
                'Body' => $message,
            ]);

        return $response->successful();
    }
}
