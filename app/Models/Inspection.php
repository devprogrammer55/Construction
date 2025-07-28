<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inspection extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'task_id',
        'inspector_id',
        'title',
        'description',
        'category',
        'scheduled_date',
        'completed_date',
        'status',
        'priority',
        'notes',
        'findings',
        'recommendations',
        'score',
        'metadata',
    ];

    protected $casts = [
        'scheduled_date' => 'datetime',
        'completed_date' => 'datetime',
        'score' => 'float',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(InspectionPhoto::class);
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'scheduled' => '#fbbf24',
            'in_progress' => '#3b82f6',
            'completed' => '#10b981',
            'failed' => '#ef4444',
            'cancelled' => '#6b7280',
            default => '#6b7280',
        };
    }

    public function getPriorityColorAttribute(): string
    {
        return match ($this->priority) {
            'low' => '#22c55e',
            'medium' => '#fbbf24',
            'high' => '#f97316',
            'critical' => '#ef4444',
            default => '#6b7280',
        };
    }

    public function getCategoryColorAttribute(): string
    {
        return match ($this->category) {
            'safety' => '#ef4444',
            'quality' => '#f97316',
            'compliance' => '#3b82f6',
            'progress' => '#10b981',
            'final' => '#8b5cf6',
            'pre_delivery' => '#06b6d4',
            default => '#6b7280',
        };
    }

    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByInspector($query, $inspectorId)
    {
        return $query->where('inspector_id', $inspectorId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeOverdue($query)
    {
        return $query->where('scheduled_date', '<', now())
                    ->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function scopeScheduledForToday($query)
    {
        return $query->whereDate('scheduled_date', today())
                    ->where('status', 'scheduled');
    }

    public function scopeScheduledForWeek($query)
    {
        return $query->whereBetween('scheduled_date', [now()->startOfWeek(), now()->endOfWeek()])
                    ->where('status', 'scheduled');
    }

    public function isOverdue(): bool
    {
        return $this->scheduled_date && $this->scheduled_date < now() && !in_array($this->status, ['completed', 'cancelled']);
    }

    public function markAsCompleted(array $data = []): void
    {
        $this->update(array_merge($data, [
            'status' => 'completed',
            'completed_date' => now(),
        ]));
    }
}
