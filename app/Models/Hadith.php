<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Hadith extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'book_id',
        'narrator_id',
        'title',
        'intro',
        'text',
        'source',
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

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function narrator(): BelongsTo
    {
        return $this->belongsTo(Narrator::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(HadithTerm::class);
    }

    public function aids(): HasMany
    {
        return $this->hasMany(HadithAid::class)->orderBy('sort_order');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(UserHadithProgress::class);
    }

    /**
     * Progress for the currently authenticated user only. Load with
     * ->load(['currentUserProgress']) so HadithResource can expose it.
     */
    public function currentUserProgress(): HasOne
    {
        return $this->hasOne(UserHadithProgress::class)
            ->where('user_id', auth('sanctum')->id());
    }

    public function stackItems(): HasMany
    {
        return $this->hasMany(MemorizationStackItem::class);
    }

    /**
     * The authenticated user's pending stack entry for this hadith, if any.
     * Load it with ->with('currentUserStackItem') so the API can tell the
     * client why a hadith is on top of the queue.
     */
    public function currentUserStackItem(): HasOne
    {
        return $this->hasOne(MemorizationStackItem::class)
            ->where('user_id', auth('sanctum')->id())
            ->whereNull('resolved_at');
    }

    public function registerMediaCollections(): void
    {
        // MIME/size validation is enforced at the HTTP layer via Form Requests.
        $this->addMediaCollection('hadith_audio')
            ->singleFile();

        $this->addMediaCollection('hadith_attachment')
            ->singleFile();
    }

    public function audioUrl(): ?string
    {
        return $this->getFirstMedia('hadith_audio')?->getFullUrl();
    }

    public function attachmentUrl(): ?string
    {
        return $this->getFirstMedia('hadith_attachment')?->getFullUrl();
    }
}
