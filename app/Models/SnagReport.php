<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SnagReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'reported_by',
        'assigned_to',
        'activity_id',
        'snag_code',
        'title',
        'description',
        'severity',
        'status',
        'category',
        'location_description',
        'location_coordinates',
        'due_date',
        'acknowledged_at',
        'resolved_at',
        'verified_at',
        'resolution_notes',
        'cost_impact',
        'time_impact_hours',
    ];

    protected $casts = [
        'location_coordinates' => 'array',
        'due_date' => 'date',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'verified_at' => 'datetime',
        'cost_impact' => 'decimal:2',
    ];

    // Relationships
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ProjectActivity::class, 'activity_id');
    }

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
                    ->whereNotIn('status', ['resolved', 'verified', 'closed']);
    }

    // Helper methods
    public function generateSnagCode(): string
    {
        $prefix = 'SNG';
        $year = date('Y');
        $suffix = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        return $prefix . $year . $suffix;
    }

    public function isOverdue(): bool
    {
        return $this->due_date && 
               $this->due_date->isPast() && 
               !in_array($this->status, ['resolved', 'verified', 'closed']);
    }

    public function acknowledge(): void
    {
        $this->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
        ]);
    }

    public function resolve(string $notes = null): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    public function verify(): void
    {
        $this->update([
            'status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    public function getSeverityColor(): string
    {
        return match($this->severity) {
            'low' => '#10b981',
            'medium' => '#f59e0b',
            'high' => '#f97316',
            'critical' => '#ef4444',
            default => '#6b7280'
        };
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            'reported' => '#fbbf24',
            'acknowledged' => '#3b82f6',
            'in_progress' => '#8b5cf6',
            'resolved' => '#10b981',
            'verified' => '#059669',
            'closed' => '#6b7280',
            'rejected' => '#ef4444',
            default => '#6b7280'
        };
    }
}
