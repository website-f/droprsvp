<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'category_id', 'title', 'slug', 'subtitle', 'description', 'cover_image', 'gallery',
        'status', 'cancelled_reason', 'visibility', 'timezone', 'is_online', 'venue_name', 'venue_address', 'city',
        'online_url', 'latitude', 'longitude', 'starts_at', 'ends_at', 'capacity', 'published_at', 'boosted_until',
    ];

    protected function casts(): array
    {
        return [
            'is_online' => 'boolean',
            'gallery' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'boosted_until' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /** Currently within a paid boost window. */
    public function isBoosted(): bool
    {
        return $this->boosted_until !== null && $this->boosted_until->isFuture();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class)->orderBy('starts_at');
    }

    public function seatingTables(): HasMany
    {
        return $this->hasMany(SeatingTable::class)->orderBy('sort_order');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(EventDailyStat::class);
    }

    /** Top-level discussion comments (replies hang off each). */
    public function comments(): HasMany
    {
        return $this->hasMany(EventComment::class)->whereNull('parent_id')->latest();
    }

    /** Attendee ratings & reviews. */
    public function reviews(): HasMany
    {
        return $this->hasMany(EventReview::class)->latest();
    }

    /**
     * Whether the user attended (holds a paid order for this event, tied to their
     * account or placed as a guest with their email). Gates who can leave a review.
     */
    public function hasAttendee(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->orders()
            ->where('status', 'paid')
            ->where(fn ($q) => $q
                ->where('user_id', $user->id)
                ->when($user->email, fn ($w) => $w->orWhere('buyer_email', $user->email)))
            ->exists();
    }

    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }

    /** Public, published, currently-visible events. */
    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', 'published')->where('visibility', 'public');
    }
}
