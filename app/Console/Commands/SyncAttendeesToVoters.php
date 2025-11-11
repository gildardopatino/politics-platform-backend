<?php

namespace App\Console\Commands;

use App\Models\MeetingAttendee;
use App\Models\Voter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncAttendeesToVoters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'voters:sync-attendees {--tenant-id= : Specific tenant ID to sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync all meeting attendees to voters table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting sync of meeting attendees to voters...');

        $query = MeetingAttendee::query();

        if ($tenantId = $this->option('tenant-id')) {
            $query->where('tenant_id', $tenantId);
            $this->info("Filtering by tenant_id: {$tenantId}");
        }

        $attendees = $query->get();
        $this->info("Found {$attendees->count()} attendees to process");

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($attendees->count());
        $bar->start();

        foreach ($attendees as $attendee) {
            try {
                $voter = Voter::withoutGlobalScopes()
                    ->where('tenant_id', $attendee->tenant_id)
                    ->where('cedula', $attendee->cedula)
                    ->first();

                if ($voter) {
                    // Update existing voter
                    $wasUpdated = $this->updateVoter($voter, $attendee);
                    if ($wasUpdated) {
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    // Create new voter
                    $this->createVoter($attendee);
                    $created++;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->error("\nError processing attendee {$attendee->cedula}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Sync completed!');
        $this->table(
            ['Action', 'Count'],
            [
                ['Created', $created],
                ['Updated', $updated],
                ['Skipped', $skipped],
                ['Errors', $errors],
                ['Total', $attendees->count()],
            ]
        );

        return Command::SUCCESS;
    }

    protected function createVoter(MeetingAttendee $attendee): void
    {
        Voter::create([
            'tenant_id' => $attendee->tenant_id,
            'cedula' => $attendee->cedula,
            'nombres' => $attendee->nombres,
            'apellidos' => $attendee->apellidos,
            'email' => $attendee->email,
            'telefono' => $attendee->telefono,
            'direccion' => $attendee->direccion,
            'barrio_id' => $attendee->barrio_id,
            'meeting_id' => $attendee->meeting_id,
            'created_by' => $attendee->created_by,
        ]);
    }

    protected function updateVoter(Voter $voter, MeetingAttendee $attendee): bool
    {
        $updated = false;

        // Update only empty fields
        if (empty($voter->email) && !empty($attendee->email)) {
            $voter->email = $attendee->email;
            $updated = true;
        }

        if (empty($voter->telefono) && !empty($attendee->telefono)) {
            $voter->telefono = $attendee->telefono;
            $updated = true;
        }

        if (empty($voter->direccion) && !empty($attendee->direccion)) {
            $voter->direccion = $attendee->direccion;
            $updated = true;
        }

        if (empty($voter->barrio_id) && !empty($attendee->barrio_id)) {
            $voter->barrio_id = $attendee->barrio_id;
            $updated = true;
        }

        // Check for conflicts
        $hasConflicts = false;

        if (!empty($voter->email) && !empty($attendee->email) && $voter->email !== $attendee->email) {
            $hasConflicts = true;
        }

        if (!empty($voter->telefono) && !empty($attendee->telefono) && $voter->telefono !== $attendee->telefono) {
            $hasConflicts = true;
        }

        if (!empty($voter->barrio_id) && !empty($attendee->barrio_id) && $voter->barrio_id !== $attendee->barrio_id) {
            $hasConflicts = true;
        }

        if ($hasConflicts && !$voter->has_multiple_records) {
            $voter->has_multiple_records = true;
            $updated = true;
        }

        if ($updated) {
            $voter->save();
        }

        return $updated;
    }
}
