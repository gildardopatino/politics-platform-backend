<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'call_id',
        'survey_question_id',
        'voter_id',
        'answer_text',
    ];

    /**
     * Relaciones
     */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function surveyQuestion(): BelongsTo
    {
        return $this->belongsTo(SurveyQuestion::class);
    }

    public function voter(): BelongsTo
    {
        return $this->belongsTo(Voter::class);
    }

    /**
     * Scopes
     */
    public function scopeByQuestion($query, int $questionId)
    {
        return $query->where('survey_question_id', $questionId);
    }

    public function scopeByVoter($query, int $voterId)
    {
        return $query->where('voter_id', $voterId);
    }
}
