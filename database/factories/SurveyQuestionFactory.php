<?php

namespace Database\Factories;

use App\Models\Survey;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SurveyQuestion>
 */
class SurveyQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            // El tenant sale de la encuesta madre: una pregunta ajena debe
            // poder fabricarse desde la sesión de otro tenant.
            'tenant_id' => fn (array $attributes) => Survey::withoutGlobalScope(TenantScope::class)
                ->whereKey($attributes['survey_id'])
                ->value('tenant_id'),
            'question_text' => fake()->sentence(4),
            'question_type' => 'text',
            'options' => null,
            'order' => 1,
            'is_required' => false,
        ];
    }

    /**
     * La pregunta cuelga de la encuesta dada.
     */
    public function forSurvey(Survey $survey): static
    {
        return $this->state(fn (array $attributes) => [
            'survey_id' => $survey->id,
            'tenant_id' => $survey->tenant_id,
        ]);
    }
}
