<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'created_by',
        'project_name',
        'project_code',
        'description',
        'client_name',
        'client_contact',
        'client_email',
        'project_address',
        'city',
        'state',
        'country',
        'postal_code',
        'budget',
        'currency',
        'start_date',
        'end_date',
        'actual_start_date',
        'actual_end_date',
        'status',
        'priority',
        'progress_percentage',
        'project_settings',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'actual_start_date' => 'date',
        'actual_end_date' => 'date',
        'budget' => 'decimal:2',
        'progress_percentage' => 'decimal:2',
        'project_settings' => 'array',
    ];

    // Relationships
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ProjectPhoto::class);
    }

    public function albums(): HasMany
    {
        return $this->hasMany(PhotoAlbum::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function snagReports(): HasMany
    {
        return $this->hasMany(SnagReport::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'ongoing');
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    // Helper methods
    public function generateProjectCode(): string
    {
        $prefix = 'PRJ';
        $year = date('Y');
        $suffix = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        return $prefix . $year . $suffix;
    }

    public function isActive(): bool
    {
        return $this->status === 'ongoing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function updateProgress(): void
    {
        $totalTasks = $this->tasks()->count();
        if ($totalTasks > 0) {
            $completedTasks = $this->tasks()->where('status', 'completed')->count();
            $this->progress_percentage = ($completedTasks / $totalTasks) * 100;
            $this->save();
        }
    }

    public function getDurationInDays(): ?int
    {
        if ($this->start_date && $this->end_date) {
            return $this->start_date->diffInDays($this->end_date);
        }
        return null;
    }

    public function getActualDurationInDays(): ?int
    {
        if ($this->actual_start_date && $this->actual_end_date) {
            return $this->actual_start_date->diffInDays($this->actual_end_date);
        }
        return null;
    }
}
