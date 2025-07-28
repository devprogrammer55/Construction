<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'designation',
        'profile_photo',
        'is_active',
        'last_login_at',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    // Relationships
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'user_companies')
                    ->withPivot(['role', 'is_primary', 'status', 'joined_at', 'invited_at', 'invitation_token'])
                    ->withTimestamps();
    }

    public function primaryCompany(): BelongsToMany
    {
        return $this->companies()->wherePivot('is_primary', true);
    }

    public function activeCompanies(): BelongsToMany
    {
        return $this->companies()->wherePivot('status', 'active');
    }

    public function createdProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'created_by');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function taskComments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function uploadedPhotos(): HasMany
    {
        return $this->hasMany(TaskPhoto::class, 'uploaded_by');
    }

    public function projectPhotos(): HasMany
    {
        return $this->hasMany(ProjectPhoto::class, 'uploaded_by');
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(ProjectDocument::class, 'uploaded_by');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'inspector_id');
    }

    public function reportedSnags(): HasMany
    {
        return $this->hasMany(SnagReport::class, 'reported_by');
    }

    public function assignedSnags(): HasMany
    {
        return $this->hasMany(SnagReport::class, 'assigned_to');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->whereHas('companies', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        });
    }

    // Helper methods
    public function hasCompany(Company $company): bool
    {
        return $this->companies()->where('company_id', $company->id)->exists();
    }

    public function getCompanyRole(Company $company): ?string
    {
        $pivot = $this->companies()->where('company_id', $company->id)->first()?->pivot;
        return $pivot?->role;
    }

    public function isCompanyOwner(Company $company): bool
    {
        return $this->getCompanyRole($company) === 'owner';
    }

    public function isCompanyAdmin(Company $company): bool
    {
        return in_array($this->getCompanyRole($company), ['owner', 'admin']);
    }

    public function canManageProject(Project $project): bool
    {
        return $this->isCompanyAdmin($project->company) || $project->created_by === $this->id;
    }

    public function getPendingTasksCount(): int
    {
        return $this->assignedTasks()
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->count();
    }

    public function getOverdueTasksCount(): int
    {
        return $this->assignedTasks()
                    ->where('due_date', '<', now())
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->count();
    }

    public function getUnreadNotificationsCount(): int
    {
        return $this->notifications()
                    ->where('is_read', false)
                    ->count();
    }

    public function updateLastLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }
}
