<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HadithAid extends Model
{
    use HasFactory;

    protected $fillable = [
        'hadith_id',
        'title',
        'content',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function hadith(): BelongsTo
    {
        return $this->belongsTo(Hadith::class);
    }
}
