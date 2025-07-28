<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name',
        'company_code',
        'description',
        'industry',
        'website',
        'phone',
        'email',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'total_employees',
        'registration_number',
        'tax_id',
        'status',
        'established_date',
    ];

    protected $casts = [
        'established_date' => 'datetime',
        'status' => 'string',
    ];

    // Relationships
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_companies')
                    ->withPivot(['role', 'is_primary', 'status', 'joined_at', 'invited_at', 'invitation_token'])
                    ->withTimestamps();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function activeUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('status', 'active');
    }

    public function owners(): BelongsToMany
    {
        return $this->users()->wherePivot('role', 'owner');
    }

    public function admins(): BelongsToMany
    {
        return $this->users()->wherePivot('role', 'admin');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Helper methods
    public function generateCompanyCode(): string
    {
        $prefix = strtoupper(substr($this->company_name, 0, 3));
        $suffix = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        return $prefix . $suffix;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getUserRole(User $user): ?string
    {
        $pivot = $this->users()->where('user_id', $user->id)->first()?->pivot;
        return $pivot?->role;
    }
}
