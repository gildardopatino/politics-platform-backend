<?php

namespace App\Http\Resources\Api\V1\Audit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'auditable_type' => $this->getModelName($this->auditable_type),
            'auditable_type_full' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'old_values' => $this->old_values ?? [],
            'new_values' => $this->new_values ?? [],
            'changes' => $this->getChanges(),
            'url' => $this->url,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'tags' => $this->tags,
            'user' => $this->when($this->user, [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
            ]),
            'user_id' => $this->user_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_at_human' => $this->created_at?->diffForHumans(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Get a human-readable model name.
     *
     * @param string|null $fullClassName
     * @return string
     */
    private function getModelName(?string $fullClassName): string
    {
        if (!$fullClassName) {
            return 'Unknown';
        }

        $parts = explode('\\', $fullClassName);
        return end($parts);
    }

    /**
     * Get formatted changes showing before and after values.
     *
     * @return array
     */
    private function getChanges(): array
    {
        $changes = [];
        $oldValues = $this->old_values ?? [];
        $newValues = $this->new_values ?? [];

        // Combine all keys from both old and new values
        $allKeys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));

        foreach ($allKeys as $key) {
            $oldValue = $oldValues[$key] ?? null;
            $newValue = $newValues[$key] ?? null;

            // Only include if there's a difference
            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                    'label' => $this->getFieldLabel($key),
                ];
            }
        }

        return $changes;
    }

    /**
     * Get a human-readable label for a field.
     *
     * @param string $field
     * @return string
     */
    private function getFieldLabel(string $field): string
    {
        // Convert snake_case to Title Case
        return ucwords(str_replace('_', ' ', $field));
    }
}
