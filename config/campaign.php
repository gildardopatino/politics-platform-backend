<?php

return [
    'batch_size' => env('CAMPAIGN_BATCH_SIZE', 100),
    'rate_limit_per_minute' => env('CAMPAIGN_RATE_LIMIT', 60),
];
