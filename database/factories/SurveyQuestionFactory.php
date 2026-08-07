<?php

namespace Database\Factories;

use App\Models\Survey;
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
        ]);
    }
}
