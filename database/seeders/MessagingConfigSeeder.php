<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MessagingConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\MessagingConfig::updateOrCreate(
            ['key' => 'email_price'],
            [
                'value' => 50.00, // 50 COP por email
                'description' => 'Price per email in COP'
            ]
        );

        \App\Models\MessagingConfig::updateOrCreate(
            ['key' => 'whatsapp_price'],
            [
                'value' => 100.00, // 100 COP por WhatsApp
                'description' => 'Price per WhatsApp message in COP'
            ]
        );

        $this->command->info('Messaging prices configured: Email=50 COP, WhatsApp=100 COP');
    }
}
