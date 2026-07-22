<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Book extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'title',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function hadiths(): HasMany
    {
        return $this->hasMany(Hadith::class);
    }

    public function registerMediaCollections(): void
    {
        // MIME/size validation is enforced at the HTTP layer via Form Requests.
        $this->addMediaCollection('book_cover')
            ->singleFile();
    }

    public function coverUrl(): ?string
    {
        $media = $this->getFirstMedia('book_cover');

        return $media?->getFullUrl();
    }
}
