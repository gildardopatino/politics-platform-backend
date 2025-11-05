<?php

namespace App\Console\Commands;

use App\Models\MeetingAttendee;
use App\Models\Voter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncVotersFromAttendees extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'voters:sync {--tenant= : Sync only for specific tenant ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza votantes desde los asistentes de reuniones';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔄 Iniciando sincronización de votantes...');

        $tenantId = $this->option('tenant');
        
        $query = MeetingAttendee::query()
            ->whereNotNull('cedula')
            ->with(['meeting', 'barrio']);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
            $this->info("📍 Sincronizando solo para tenant ID: {$tenantId}");
        }

        $attendees = $query->get();
        $this->info("📊 Total de asistentes encontrados: {$attendees->count()}");

        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'flagged' => 0,
        ];

        $bar = $this->output->createProgressBar($attendees->count());
        $bar->start();

        foreach ($attendees as $attendee) {
            $result = $this->syncVoter($attendee);
            $stats[$result]++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('✅ Sincronización completada:');
        $this->table(
            ['Estado', 'Cantidad'],
            [
                ['Nuevos votantes creados', $stats['created']],
                ['Votantes actualizados', $stats['updated']],
                ['Votantes omitidos (sin cambios)', $stats['skipped']],
                ['Votantes marcados con múltiples registros', $stats['flagged']],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Sincroniza un votante desde un asistente
     */
    protected function syncVoter(MeetingAttendee $attendee): string
    {
        $voter = Voter::where('tenant_id', $attendee->tenant_id)
            ->where('cedula', $attendee->cedula)
            ->first();

        // Si el votante no existe, crearlo
        if (!$voter) {
            Voter::create([
                'tenant_id' => $attendee->tenant_id,
                'cedula' => $attendee->cedula,
                'nombres' => $attendee->nombres,
                'apellidos' => $attendee->apellidos,
                'email' => $attendee->email,
                'telefono' => $attendee->telefono,
                'direccion' => null,
                'barrio_id' => $attendee->barrio_id,
                'corregimiento_id' => $attendee->corregimiento_id,
                'vereda_id' => $attendee->vereda_id,
                'meeting_id' => $attendee->meeting_id, // Primera reunión
                'has_multiple_records' => false,
                'created_by' => $attendee->created_by,
            ]);

            return 'created';
        }

        // Si el votante existe, verificar si hay cambios
        $hasChanges = false;
        $dataChanged = false;

        $fieldsToCheck = ['nombres', 'apellidos', 'email', 'telefono', 'barrio_id', 'corregimiento_id', 'vereda_id'];

        foreach ($fieldsToCheck as $field) {
            $attendeeValue = $attendee->$field;
            $voterValue = $voter->$field;

            // Si el valor del asistente no es null y es diferente al del votante
            if ($attendeeValue !== null && $attendeeValue !== $voterValue) {
                $hasChanges = true;
                
                // Si el votante ya tenía un valor diferente, marcar que tiene múltiples registros
                if ($voterValue !== null && $voterValue !== $attendeeValue) {
                    $dataChanged = true;
                }
            }
        }

        if ($hasChanges) {
            // Actualizar con los datos más recientes (asumiendo que los más recientes son correctos)
            $updateData = [];

            foreach ($fieldsToCheck as $field) {
                if ($attendee->$field !== null) {
                    $updateData[$field] = $attendee->$field;
                }
            }

            if ($dataChanged && !$voter->has_multiple_records) {
                $updateData['has_multiple_records'] = true;
            }

            $voter->update($updateData);

            return $dataChanged ? 'flagged' : 'updated';
        }

        return 'skipped';
    }
}
