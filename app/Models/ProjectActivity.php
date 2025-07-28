<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'activity_name',
        'description',
        'sequence_order',
        'planned_start_date',
        'planned_end_date',
        'actual_start_date',
        'actual_end_date',
        'status',
        'progress_percentage',
        'budget_allocated',
        'actual_cost',
        'activity_metadata',
    ];

    protected $casts = [
        'planned_start_date' => 'date',
        'planned_end_date' => 'date',
        'actual_start_date' => 'date',
        'actual_end_date' => 'date',
        'progress_percentage' => 'decimal:2',
        'budget_allocated' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'activity_metadata' => 'array',
    ];

    // Relationships
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'activity_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'activity_id');
    }

    public function snagReports(): HasMany
    {
        return $this->hasMany(SnagReport::class, 'activity_id');
    }

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOrderedBySequence($query)
    {
        return $query->orderBy('sequence_order');
    }

    // Helper methods
    public function updateProgress(): void
    {
        $totalTasks = $this->tasks()->count();
        if ($totalTasks > 0) {
            $completedTasks = $this->tasks()->where('status', 'completed')->count();
            $this->progress_percentage = ($completedTasks / $totalTasks) * 100;
            $this->save();
        }
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function getDurationInDays(): ?int
    {
        if ($this->planned_start_date && $this->planned_end_date) {
            return $this->planned_start_date->diffInDays($this->planned_end_date);
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

    public function getBudgetVariance(): ?float
    {
        if ($this->budget_allocated && $this->actual_cost) {
            return $this->actual_cost - $this->budget_allocated;
        }
        return null;
    }
}
