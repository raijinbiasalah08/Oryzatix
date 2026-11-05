<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiceScan extends Model
{
    use HasFactory;

    protected $table = 'scans';

    protected $fillable = [
        'user_id',
        'image_path',
        'image_data',
        'disease_name',
        'scientific_name',
        'confidence',
        'severity',
        'severity_source',
        'notes',
        'treatment_recommendation',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'treatment_recommendation' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
