<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Narrator extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'biography',
    ];

    public function hadiths(): HasMany
    {
        return $this->hasMany(Hadith::class);
    }
}
