<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PrioritySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $priorities = [
            ['name' => 'Baja', 'color' => '#28a745', 'order' => 1],
            ['name' => 'Media', 'color' => '#ffc107', 'order' => 2],
            ['name' => 'Alta', 'color' => '#fd7e14', 'order' => 3],
            ['name' => 'Urgente', 'color' => '#dc3545', 'order' => 4],
        ];

        foreach ($priorities as $priority) {
            \App\Models\Priority::create($priority);
        }
    }
}
