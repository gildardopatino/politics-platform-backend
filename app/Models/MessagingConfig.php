<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class MessagingConfig extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'messaging_config';

    protected $fillable = [
        'key',
        'value',
        'description',
    ];

    protected $casts = [
        'value' => 'decimal:2',
    ];

    // Available config keys
    const KEY_EMAIL_PRICE = 'email_price';
    const KEY_WHATSAPP_PRICE = 'whatsapp_price';

    /**
     * Get email price
     */
    public static function getEmailPrice(): float
    {
        return static::where('key', self::KEY_EMAIL_PRICE)->value('value') ?? 0;
    }

    /**
     * Get WhatsApp price
     */
    public static function getWhatsAppPrice(): float
    {
        return static::where('key', self::KEY_WHATSAPP_PRICE)->value('value') ?? 0;
    }

    /**
     * Update email price (only superadmin)
     */
    public static function setEmailPrice(float $price): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_EMAIL_PRICE],
            ['value' => $price, 'description' => 'Price per email in COP']
        );
    }

    /**
     * Update WhatsApp price (only superadmin)
     */
    public static function setWhatsAppPrice(float $price): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_WHATSAPP_PRICE],
            ['value' => $price, 'description' => 'Price per WhatsApp message in COP']
        );
    }
}
