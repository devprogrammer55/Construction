<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\Inspection;
use App\Models\SnagReport;
use App\Models\Document;
use App\Models\PhotoAlbum;
use App\Models\Photo;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function overview(Request $request)
    {
        $user = $request->user();
        $company = $user->currentCompany;

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'No company selected'
            ], 400);
        }

        $projectIds = $company->projects()->pluck('id');

        $overview = [
            'company' => [
                'name' => $company->name,
                'total_projects' => $company->projects()->count(),
                'total_users' => $company->users()->count(),
            ],
            'projects' => [
                'total' => Project::whereIn('id', $projectIds)->count(),
                'active' => Project::whereIn('id', $projectIds)->where('status', 'active')->count(),
                'completed' => Project::whereIn('id', $projectIds)->where('status', 'completed')->count(),
                'on_hold' => Project::whereIn('id', $projectIds)->where('status', 'on_hold')->count(),
            ],
            'tasks' => [
                'total' => Task::whereIn('project_id', $projectIds)->count(),
                'pending' => Task::whereIn('project_id', $projectIds)->where('status', 'pending')->count(),
                'in_progress' => Task::whereIn('project_id', $projectIds)->where('status', 'in_progress')->count(),
                'completed' => Task::whereIn('project_id', $projectIds)->where('status', 'completed')->count(),
                'overdue' => Task::whereIn('project_id', $projectIds)->where('due_date', '<', now())->count(),
            ],
            'inspections' => [
                'total' => Inspection::whereIn('project_id', $projectIds)->count(),
                'scheduled' => Inspection::whereIn('project_id', $projectIds)->where('status', 'scheduled')->count(),
                'in_progress' => Inspection::whereIn('project_id', $projectIds)->where('status', 'in_progress')->count(),
                'completed' => Inspection::whereIn('project_id', $projectIds)->where('status', 'completed')->count(),
                'overdue' => Inspection::whereIn('project_id', $projectIds)->where('scheduled_date', '<', now())->count(),
            ],
            'snags' => [
                'total' => SnagReport::whereIn('project_id', $projectIds)->count(),
                'open' => SnagReport::whereIn('project_id', $projectIds)->where('status', 'open')->count(),
                'in_progress' => SnagReport::whereIn('project_id', $projectIds)->where('status', 'in_progress')->count(),
                'resolved' => SnagReport::whereIn('project_id', $projectIds)->where('status', 'resolved')->count(),
                'overdue' => SnagReport::whereIn('project_id', $projectIds)->where('due_date', '<', now())->count(),
            ],
            'documents' => [
                'total' => Document::whereIn('project_id', $projectIds)->currentVersion()->count(),
                'by_category' => Document::whereIn('project_id', $projectIds)->currentVersion()
                    ->select('category', DB::raw('count(*) as count'))
                    ->groupBy('category')
                    ->pluck('count', 'category'),
            ],
            'photos' => [
                'total_albums' => PhotoAlbum::whereIn('project_id', $projectIds)->count(),
                'total_photos' => Photo::whereHas('album', function ($query) use ($projectIds) {
                    $query->whereIn('project_id', $projectIds);
                })->count(),
            ],
            'notifications' => [
                'unread' => Notification::where('user_id', $user->id)->unread()->count(),
                'recent' => Notification::where('user_id', $user->id)->recent(7)->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $overview
        ]);
    }

    public function projectAnalytics(Request $request, Project $project)
    {
        $user = $request->user();
        
        if (!$user->currentCompany || $project->company_id !== $user->currentCompany->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $analytics = [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'progress' => $project->progress,
                'start_date' => $project->start_date,
                'end_date' => $project->end_date,
            ],
            'tasks' => [
                'total' => $project->tasks()->count(),
                'by_status' => $project->tasks()->select('status', DB::raw('count(*) as count'))
                    ->groupBy('status')
                    ->pluck('count', 'status'),
                'by_priority' => $project->tasks()->select('priority', DB::raw('count(*) as count'))
                    ->groupBy('priority')
                    ->pluck('count', 'priority'),
                'overdue' => $project->tasks()->where('due_date', '<', now())->count(),
                'completed_today' => $project->tasks()->whereDate('completed_at', today())->count(),
            ],
            'inspections' => [
                'total' => $project->inspections()->count(),
                'by_status' => $project->inspections()->select('status', DB::raw('count(*) as count'))
                    ->groupBy('status')
                    ->pluck('count', 'status'),
                'by_category' => $project->inspections()->select('category', DB::raw('count(*) as count'))
                    ->groupBy('category')
                    ->pluck('count', 'category'),
                'overdue' => $project->inspections()->where('scheduled_date', '<', now())->count(),
            ],
            'snags' => [
                'total' => $project->snagReports()->count(),
                'by_status' => $project->snagReports()->select('status', DB::raw('count(*) as count'))
                    ->groupBy('status')
                    ->pluck('count', 'status'),
                'by_severity' => $project->snagReports()->select('severity', DB::raw('count(*) as count'))
                    ->groupBy('severity')
                    ->pluck('count', 'severity'),
                'overdue' => $project->snagReports()->where('due_date', '<', now())->count(),
            ],
            'documents' => [
                'total' => $project->documents()->currentVersion()->count(),
                'by_category' => $project->documents()->currentVersion()
                    ->select('category', DB::raw('count(*) as count'))
                    ->groupBy('category')
                    ->pluck('count', 'category'),
            ],
            'photos' => [
                'total_albums' => $project->photoAlbums()->count(),
                'total_photos' => Photo::whereHas('album', function ($query) use ($project) {
                    $query->where('project_id', $project->id);
                })->count(),
            ],
            'team' => [
                'total_members' => $project->team->users()->count(),
                'active_members' => $project->team->users()->where('is_active', true)->count(),
            ],
            'recent_activity' => [
                'last_task_completed' => $project->tasks()->whereNotNull('completed_at')->latest('completed_at')->first(),
                'last_inspection_completed' => $project->inspections()->whereNotNull('completed_at')->latest('completed_at')->first(),
                'last_snag_resolved' => $project->snagReports()->where('status', 'resolved')->latest('resolved_at')->first(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $analytics
        ]);
    }

    public function userStats(Request $request)
    {
        $user = $request->user();
        $company = $user->currentCompany;

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'No company selected'
            ], 400);
        }

        $projectIds = $company->projects()->pluck('id');

        $stats = [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->currentTeamRole,
                'joined_at' => $user->created_at,
            ],
            'tasks' => [
                'assigned' => Task::whereIn('project_id', $projectIds)
                    ->where('assigned_to', $user->id)->count(),
                'completed' => Task::whereIn('project_id', $projectIds)
                    ->where('assigned_to', $user->id)
                    ->where('status', 'completed')->count(),
                'in_progress' => Task::whereIn('project_id', $projectIds)
                    ->where('assigned_to', $user->id)
                    ->where('status', 'in_progress')->count(),
                'overdue' => Task::whereIn('project_id', $projectIds)
                    ->where('assigned_to', $user->id)
                    ->where('due_date', '<', now())->count(),
            ],
            'inspections' => [
                'assigned' => Inspection::whereIn('project_id', $projectIds)
                    ->where('inspector_id', $user->id)->count(),
                'completed' => Inspection::whereIn('project_id', $projectIds)
                    ->where('inspector_id', $user->id)
                    ->where('status', 'completed')->count(),
                'scheduled' => Inspection::whereIn('project_id', $projectIds)
                    ->where('inspector_id', $user->id)
                    ->where('status', 'scheduled')->count(),
            ],
            'snags' => [
                'reported' => SnagReport::whereIn('project_id', $projectIds)
                    ->where('reported_by', $user->id)->count(),
                'assigned' => SnagReport::whereIn('project_id', $projectIds)
                    ->where('assigned_to', $user->id)->count(),
                'resolved' => SnagReport::whereIn('project_id', $projectIds)
                    ->where('assigned_to', $user->id)
                    ->where('status', 'resolved')->count(),
            ],
            'documents' => [
                'uploaded' => Document::whereIn('project_id', $projectIds)
                    ->where('uploaded_by', $user->id)->count(),
            ],
            'photos' => [
                'uploaded' => Photo::whereHas('album.project', function ($query) use ($company) {
                    $query->where('company_id', $company->id);
                })->where('uploaded_by', $user->id)->count(),
            ],
            'notifications' => [
                'unread' => Notification::where('user_id', $user->id)->unread()->count(),
                'today' => Notification::where('user_id', $user->id)->whereDate('created_at', today())->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    public function recentActivity(Request $request)
    {
        $user = $request->user();
        $company = $user->currentCompany;

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'No company selected'
            ], 400);
        }

        $projectIds = $company->projects()->pluck('id');

        $recentActivity = [
            'tasks' => Task::whereIn('project_id', $projectIds)
                ->with(['project', 'assignedTo'])
                ->orderBy('updated_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($task) {
                    return [
                        'type' => 'task',
                        'title' => $task->title,
                        'status' => $task->status,
                        'project' => $task->project->name,
                        'user' => $task->assignedTo->name,
                        'timestamp' => $task->updated_at,
                    ];
                }),
            'inspections' => Inspection::whereIn('project_id', $projectIds)
                ->with(['project', 'inspector'])
                ->orderBy('updated_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($inspection) {
                    return [
                        'type' => 'inspection',
                        'title' => $inspection->title,
                        'status' => $inspection->status,
                        'project' => $inspection->project->name,
                        'user' => $inspection->inspector->name,
                        'timestamp' => $inspection->updated_at,
                    ];
                }),
            'snags' => SnagReport::whereIn('project_id', $projectIds)
                ->with(['project', 'reportedBy'])
                ->orderBy('updated_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($snag) {
                    return [
                        'type' => 'snag',
                        'title' => $snag->title,
                        'status' => $snag->status,
                        'project' => $snag->project->name,
                        'user' => $snag->reportedBy->name,
                        'timestamp' => $snag->updated_at,
                    ];
                }),
            'documents' => Document::whereIn('project_id', $projectIds)
                ->currentVersion()
                ->with(['project', 'uploadedBy'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($document) {
                    return [
                        'type' => 'document',
                        'title' => $document->name,
                        'category' => $document->category,
                        'project' => $document->project->name,
                        'user' => $document->uploadedBy->name,
                        'timestamp' => $document->created_at,
                    ];
                }),
        ];

        // Flatten and sort all activities by timestamp
        $allActivities = collect()
            ->merge($recentActivity['tasks'])
            ->merge($recentActivity['inspections'])
            ->merge($recentActivity['snags'])
            ->merge($recentActivity['documents'])
            ->sortByDesc('timestamp')
            ->take(20)
            ->values();

        return response()->json([
            'success' => true,
            'data' => $allActivities
        ]);
    }
}
