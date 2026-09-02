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
        'show_participants', 'show_reviews', 'seating_enabled', 'ticketing_mode', 'auto_assign_tables',
        'status', 'cancelled_reason', 'appeal_status', 'appeal_reason', 'appeal_attachments', 'appealed_at',
        'visibility', 'timezone', 'is_online', 'venue_name', 'venue_address', 'city',
        'online_url', 'latitude', 'longitude', 'starts_at', 'ends_at', 'capacity', 'published_at', 'boosted_until',
    ];

    protected function casts(): array
    {
        return [
            'is_online' => 'boolean',
            'show_participants' => 'boolean',
            'show_reviews' => 'boolean',
            'seating_enabled' => 'boolean',
            'auto_assign_tables' => 'boolean',
            'appeal_attachments' => 'array',
            'appealed_at' => 'datetime',
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

    public function seatSections(): HasMany
    {
        return $this->hasMany(SeatSection::class)->orderBy('sort_order');
    }

    public function props(): HasMany
    {
        return $this->hasMany(EventProp::class)->orderBy('sort_order');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
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

    /** Ratings & reviews (any signed-in user, except the organizer). */
    public function reviews(): HasMany
    {
        return $this->hasMany(EventReview::class)->latest();
    }

    public function photos(): HasMany
    {
        return $this->hasMany(EventPhoto::class)->latest();
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
