<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'activity_id',
        'assigned_to',
        'created_by',
        'task_name',
        'description',
        'instructions',
        'priority',
        'status',
        'due_date',
        'started_at',
        'completed_at',
        'estimated_hours',
        'actual_hours',
        'progress_percentage',
        'task_metadata',
        'completion_notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'progress_percentage' => 'decimal:2',
        'task_metadata' => 'array',
    ];

    // Relationships
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ProjectActivity::class, 'activity_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TaskPhoto::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
                    ->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function scopeDueToday($query)
    {
        return $query->whereDate('due_date', today())
                    ->whereNotIn('status', ['completed', 'cancelled']);
    }

    // Helper methods
    public function isOverdue(): bool
    {
        return $this->due_date && 
               $this->due_date->isPast() && 
               !in_array($this->status, ['completed', 'cancelled']);
    }

    public function isDueToday(): bool
    {
        return $this->due_date && 
               $this->due_date->isToday() && 
               !in_array($this->status, ['completed', 'cancelled']);
    }

    public function markAsStarted(): void
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(string $notes = null): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'progress_percentage' => 100,
            'completion_notes' => $notes,
        ]);

        // Update project progress
        $this->project->updateProgress();
    }

    public function getDurationInHours(): ?float
    {
        if ($this->started_at && $this->completed_at) {
            return $this->started_at->diffInHours($this->completed_at);
        }
        return null;
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            'pending' => '#fbbf24',
            'in_progress' => '#3b82f6',
            'review' => '#8b5cf6',
            'completed' => '#10b981',
            'cancelled' => '#ef4444',
            default => '#6b7280'
        };
    }

    public function getPriorityColor(): string
    {
        return match($this->priority) {
            'low' => '#10b981',
            'medium' => '#f59e0b',
            'high' => '#f97316',
            'urgent' => '#ef4444',
            default => '#6b7280'
        };
    }
}
