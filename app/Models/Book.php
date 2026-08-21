<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    public function userBooks(): HasMany
    {
        return $this->hasMany(UserBook::class);
    }

    /**
     * The authenticated user's learning-list entry for this book, if any. Every
     * active book is readable by every user; this relation only says whether
     * the user added it to start memorizing (تسميع).
     */
    public function currentUserBook(): HasOne
    {
        return $this->hasOne(UserBook::class)
            ->where('user_id', auth('sanctum')->id());
    }

    /**
     * The authenticated user's progress rows across this book's hadiths. Used
     * with withCount() so a book listing can show how much of it is memorized
     * without an N+1.
     */
    public function currentUserProgress(): HasManyThrough
    {
        return $this->hasManyThrough(UserHadithProgress::class, Hadith::class)
            ->where('user_hadith_progress.user_id', auth('sanctum')->id());
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
