<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

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
        ];
    }

    /** Whether the user currently holds an active premium membership. */
    public function isPremium(): bool
    {
        return $this->premium_until !== null && $this->premium_until->isFuture();
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

    /** Events this user hosts. */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
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
