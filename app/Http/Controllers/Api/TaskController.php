<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskPhoto;
use App\Models\TaskComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $tasks = Task::with(['project', 'activity', 'assignedTo'])
            ->whereHas('project', function ($query) use ($user) {
                $query->whereHas('company', function ($q) use ($user) {
                    $q->whereHas('users', function ($uq) use ($user) {
                        $uq->where('user_id', $user->id);
                    });
                });
            })
            ->when($request->project_id, function ($query, $projectId) {
                return $query->where('project_id', $projectId);
            })
            ->when($request->status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->priority, function ($query, $priority) {
                return $query->where('priority', $priority);
            })
            ->when($request->assigned_to, function ($query, $assignedTo) {
                return $query->where('assigned_to', $assignedTo);
            })
            ->when($request->search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $tasks
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|exists:projects,id',
            'activity_id' => 'nullable|exists:project_activities,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'priority' => 'required|in:low,medium,high,critical',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'due_date' => 'required|date|after:start_date',
            'estimated_hours' => 'nullable|numeric|min:0',
            'budget' => 'nullable|numeric|min:0',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $task = Task::create([
                'project_id' => $request->project_id,
                'activity_id' => $request->activity_id,
                'name' => $request->name,
                'description' => $request->description,
                'assigned_to' => $request->assigned_to,
                'status' => 'pending',
                'priority' => $request->priority,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'due_date' => $request->due_date,
                'estimated_hours' => $request->estimated_hours ?? 0,
                'actual_hours' => 0,
                'budget' => $request->budget ?? 0,
                'progress' => 0,
                'tags' => $request->tags ?? [],
                'metadata' => [],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Task created successfully',
                'data' => $task->load(['project', 'activity', 'assignedTo'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, Task $task)
    {
        $task->load([
            'project',
            'activity',
            'assignedTo',
            'photos',
            'comments.user',
            'snagReports'
        ]);

        return response()->json([
            'success' => true,
            'data' => $task
        ]);
    }

    public function update(Request $request, Task $task)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'priority' => 'sometimes|in:low,medium,high,critical',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'due_date' => 'sometimes|date|after:start_date',
            'estimated_hours' => 'nullable|numeric|min:0',
            'budget' => 'nullable|numeric|min:0',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $task->update($request->all());
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Task updated successfully',
                'data' => $task->load(['project', 'activity', 'assignedTo'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, Task $task)
    {
        DB::beginTransaction();
        try {
            // Delete associated photos
            foreach ($task->photos as $photo) {
                if ($photo->file_path) {
                    Storage::disk('public')->delete($photo->file_path);
                }
                $photo->delete();
            }

            $task->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Task deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, Task $task)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,in_progress,completed,on_hold,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $task->update(['status' => $request->status]);

        if ($request->status === 'completed') {
            $task->markAsCompleted();
        }

        return response()->json([
            'success' => true,
            'message' => 'Task status updated successfully',
            'data' => $task
        ]);
    }

    public function updateProgress(Request $request, Task $task)
    {
        $validator = Validator::make($request->all(), [
            'progress' => 'required|integer|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $task->updateProgress($request->progress);

        return response()->json([
            'success' => true,
            'message' => 'Task progress updated successfully',
            'data' => $task
        ]);
    }

    public function assignUser(Request $request, Task $task)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $task->update(['assigned_to' => $request->user_id]);

        return response()->json([
            'success' => true,
            'message' => 'Task assigned successfully',
            'data' => $task->load('assignedTo')
        ]);
    }

    public function uploadPhotos(Request $request, Task $task)
    {
        $validator = Validator::make($request->all(), [
            'photos' => 'required|array',
            'photos.*' => 'image|mimes:jpeg,png,jpg|max:2048',
            'descriptions' => 'nullable|array',
            'descriptions.*' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $uploadedPhotos = [];

            foreach ($request->file('photos') as $index => $photo) {
                $path = $photo->store('task_photos', 'public');
                
                $taskPhoto = TaskPhoto::create([
                    'task_id' => $task->id,
                    'file_path' => $path,
                    'file_name' => $photo->getClientOriginalName(),
                    'file_size' => $photo->getSize(),
                    'mime_type' => $photo->getMimeType(),
                    'description' => $request->descriptions[$index] ?? null,
                    'uploaded_by' => $request->user()->id,
                ]);

                $uploadedPhotos[] = $taskPhoto;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Photos uploaded successfully',
                'data' => $uploadedPhotos
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload photos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPhotos(Request $request, Task $task)
    {
        $photos = $task->photos()->with('uploadedBy')->get();

        return response()->json([
            'success' => true,
            'data' => $photos
        ]);
    }

    public function addComment(Request $request, Task $task)
    {
        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:1000',
            'parent_id' => 'nullable|exists:task_comments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'content' => $request->content,
            'parent_id' => $request->parent_id,
        ]);

        $comment->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Comment added successfully',
            'data' => $comment
        ], 201);
    }

    public function getComments(Request $request, Task $task)
    {
        $comments = $task->comments()
            ->with(['user', 'replies.user'])
            ->whereNull('parent_id')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $comments
        ]);
    }

    public function summary(Request $request)
    {
        $user = $request->user();
        $company = $user->currentCompany;

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'No company selected'
            ], 400);
        }

        $summary = [
            'total_tasks' => Task::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->count(),
            'my_tasks' => Task::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('assigned_to', $user->id)->count(),
            'pending_tasks' => Task::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('status', 'pending')->count(),
            'in_progress_tasks' => Task::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('status', 'in_progress')->count(),
            'completed_tasks' => Task::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('status', 'completed')->count(),
            'overdue_tasks' => Task::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('due_date', '<', now())
              ->whereNotIn('status', ['completed', 'cancelled'])
              ->count(),
            'tasks_by_priority' => Task::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->select('priority', DB::raw('count(*) as count'))
              ->groupBy('priority')
              ->pluck('count', 'priority'),
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }
}
