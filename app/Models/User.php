<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'google_id', 'avatar', 'password', 'phone', 'gender', 'age_band', 'city', 'country', 'notification_preferences', 'profile_completed_at', 'payout_bank_code', 'payout_bank_account_number', 'payout_bank_account_name', 'chip_bank_account_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'premium_until' => 'datetime',
            'profile_completed_at' => 'datetime',
            'disabled_at' => 'datetime',
            'must_set_password' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Non-transactional notification channels the user can opt out of. Keys map
     * to the toggles on Settings → Notifications; every category defaults to on.
     * Transactional mail (receipts, tickets, refund decisions, password) always
     * sends and is intentionally not listed here.
     *
     * @var array<string, string>
     */
    public const NOTIFICATION_CHANNELS = [
        'product_news' => 'Product news & announcements from DropRSVP',
        'event_reminders' => 'Reminders before events you’re attending',
        'organizer_updates' => 'Updates from organizers you follow',
    ];

    /** This user's notification settings, merged over the all-on defaults. */
    public function notificationSettings(): array
    {
        $saved = is_array($this->notification_preferences) ? $this->notification_preferences : [];

        $out = [];
        foreach (array_keys(self::NOTIFICATION_CHANNELS) as $key) {
            $out[$key] = (bool) ($saved[$key] ?? true);
        }

        return $out;
    }

    /** Whether the user still wants a given non-transactional notification. */
    public function wantsNotification(string $channel): bool
    {
        $saved = is_array($this->notification_preferences) ? $this->notification_preferences : [];

        return (bool) ($saved[$channel] ?? true);
    }

    /** Whether the user currently holds an active premium membership. */
    public function isPremium(): bool
    {
        return $this->premium_until !== null && $this->premium_until->isFuture();
    }

    /** A disabled (suspended) account can't sign in and must be disabled before deletion. */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Premium-level access for gating (full member lists, posting in discussions).
     * Superadmins always have it (platform owner); they never subscribe.
     */
    public function hasPremiumAccess(): bool
    {
        return $this->hasRole('superadmin') || $this->isPremium();
    }

    /** Superadmins run the platform and don't buy the consumer Premium membership. */
    public function canSubscribeToPremium(): bool
    {
        return ! $this->hasRole('superadmin');
    }

    /** A URL-safe organizer handle derived from the name, de-duplicated with a count. */
    public static function uniqueSlug(string $name): string
    {
        $base = \Illuminate\Support\Str::slug($name) ?: 'organizer';
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Return this user's organizer slug, generating + saving one if missing. */
    public function ensureSlug(): string
    {
        if (blank($this->slug)) {
            $this->slug = static::uniqueSlug($this->name);
            $this->save();
        }

        return $this->slug;
    }

    /** Events this user hosts. */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** In-app bell notifications (distinct from Laravel's Notifiable channel). */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class)->latest();
    }

    public function organizerProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(OrganizerProfile::class);
    }

    /** Organizers this user follows. */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'organizer_id')->withTimestamps();
    }

    /** Users who follow this organizer. */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'organizer_id', 'follower_id')->withTimestamps();
    }

    public function isFollowing(User $organizer): bool
    {
        return $this->following()->whereKey($organizer->id)->exists();
    }
}
