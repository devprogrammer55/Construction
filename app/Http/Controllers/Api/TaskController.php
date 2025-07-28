<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Task;
use App\Models\Project;
use App\Models\User;
use App\Models\UserCompany;
use App\Models\TaskPhoto;
use Exception;

class TaskController extends Controller
{
    /**
     * Display a listing of tasks with filtering and pagination
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $user = $request->attributes->get('user');
            
            // Validate request parameters
            $validator = Validator::make($request->all(), [
                'project_id' => 'sometimes|integer|exists:projects,id',
                'status' => 'sometimes|string|in:pending,in_progress,completed,cancelled',
                'priority' => 'sometimes|string|in:low,medium,high,urgent',
                'assigned_to' => 'sometimes|integer|exists:users,id',
                'search' => 'sometimes|string|max:255',
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'sort_by' => 'sometimes|string|in:created_at,updated_at,due_date,priority',
                'sort_order' => 'sometimes|string|in:asc,desc'
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            // Get user's active companies
            $userCompanies = UserCompany::where('user_id', $user->id)
                ->where('status', 'active')
                ->pluck('company_id');

            if ($userCompanies->isEmpty()) {
                return $this->errorResponse('User is not associated with any company', 403, 'NO_COMPANY_ACCESS');
            }

            // Build query
            $query = Task::query()
                ->whereHas('project', function ($q) use ($userCompanies) {
                    $q->whereIn('company_id', $userCompanies);
                })
                ->with(['project', 'assignedUser', 'createdBy', 'photos']);

            // Apply filters
            if ($request->has('project_id')) {
                $query->where('project_id', $request->project_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('priority')) {
                $query->where('priority', $request->priority);
            }

            if ($request->has('assigned_to')) {
                $query->where('assigned_to', $request->assigned_to);
            }

            if ($request->has('search')) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('title', 'like', "%{$searchTerm}%")
                      ->orWhere('description', 'like', "%{$searchTerm}%")
                      ->orWhere('code', 'like', "%{$searchTerm}%");
                });
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = min($request->get('per_page', 20), 100);
            $tasks = $query->paginate($perPage);

            // Transform data
            $transformedTasks = $tasks->map(function ($task) {
                return [
                    'id' => $task->id,
                    'code' => $task->code,
                    'title' => $task->title,
                    'description' => $task->description,
                    'project' => [
                        'id' => $task->project->id,
                        'name' => $task->project->name,
                        'code' => $task->project->code
                    ],
                    'assigned_to' => $task->assignedUser ? [
                        'id' => $task->assignedUser->id,
                        'name' => $task->assignedUser->name,
                        'email' => $task->assignedUser->email
                    ] : null,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date ? $task->due_date->toISOString() : null,
                    'estimated_hours' => $task->estimated_hours,
                    'actual_hours' => $task->actual_hours,
                    'progress' => $task->progress,
                    'photo_count' => $task->photos->count(),
                    'created_by' => [
                        'id' => $task->createdBy->id,
                        'name' => $task->createdBy->name
                    ],
                    'created_at' => $task->created_at->toISOString(),
                    'updated_at' => $task->updated_at->toISOString()
                ];
            });

            return $this->successResponse(
                'Tasks retrieved successfully',
                [
                    'tasks' => $transformedTasks,
                    'pagination' => [
                        'current_page' => $tasks->currentPage(),
                        'per_page' => $tasks->perPage(),
                        'total' => $tasks->total(),
                        'last_page' => $tasks->lastPage(),
                        'from' => $tasks->firstItem(),
                        'to' => $tasks->lastItem()
                    ]
                ]
            );

        } catch (Exception $e) {
            Log::error('Error retrieving tasks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->attributes->get('user_id')
            ]);

            return $this->errorResponse('Failed to retrieve tasks', 500, 'TASK_RETRIEVAL_ERROR');
        }
    }

    /**
     * Store a newly created task
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $user = $request->attributes->get('user');

            // Validate request data
            $validator = Validator::make($request->all(), [
                'project_id' => 'required|integer|exists:projects,id',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'assigned_to' => 'nullable|integer|exists:users,id',
                'priority' => 'required|string|in:low,medium,high,urgent',
                'due_date' => 'nullable|date|after_or_equal:today',
                'estimated_hours' => 'nullable|numeric|min:0',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:50'
            ], [
                'project_id.required' => 'Project is required',
                'project_id.exists' => 'Invalid project selected',
                'title.required' => 'Task title is required',
                'title.max' => 'Task title cannot exceed 255 characters',
                'priority.required' => 'Priority is required',
                'priority.in' => 'Priority must be low, medium, high, or urgent',
                'due_date.date' => 'Due date must be a valid date',
                'due_date.after_or_equal' => 'Due date must be today or in the future',
                'estimated_hours.numeric' => 'Estimated hours must be a number',
                'estimated_hours.min' => 'Estimated hours must be 0 or greater',
                'tags.array' => 'Tags must be provided as an array',
                'tags.*.string' => 'Each tag must be a string',
                'tags.*.max' => 'Each tag cannot exceed 50 characters'
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            // Verify project access
            $project = Project::findOrFail($request->project_id);
            $userCompany = UserCompany::where('user_id', $user->id)
                ->where('company_id', $project->company_id)
                ->where('status', 'active')
                ->first();

            if (!$userCompany) {
                return $this->errorResponse('You do not have access to this project', 403, 'PROJECT_ACCESS_DENIED');
            }

            // Generate unique task code
            $taskCode = 'TASK-' . strtoupper(Str::random(8));
            while (Task::where('code', $taskCode)->exists()) {
                $taskCode = 'TASK-' . strtoupper(Str::random(8));
            }

            // Create task
            $task = Task::create([
                'code' => $taskCode,
                'project_id' => $request->project_id,
                'title' => $request->title,
                'description' => $request->description,
                'assigned_to' => $request->assigned_to,
                'priority' => $request->priority,
                'status' => 'pending',
                'due_date' => $request->due_date,
                'estimated_hours' => $request->estimated_hours,
                'progress' => 0,
                'created_by' => $user->id,
                'tags' => $request->tags ? json_encode($request->tags) : null
            ]);

            // Load relationships
            $task->load(['project', 'assignedUser', 'createdBy']);

            return $this->successResponse(
                'Task created successfully',
                [
                    'task' => [
                        'id' => $task->id,
                        'code' => $task->code,
                        'title' => $task->title,
                        'description' => $task->description,
                        'project' => [
                            'id' => $task->project->id,
                            'name' => $task->project->name,
                            'code' => $task->project->code
                        ],
                        'assigned_to' => $task->assignedUser ? [
                            'id' => $task->assignedUser->id,
                            'name' => $task->assignedUser->name,
                            'email' => $task->assignedUser->email
                        ] : null,
                        'priority' => $task->priority,
                        'status' => $task->status,
                        'due_date' => $task->due_date ? $task->due_date->toISOString() : null,
                        'estimated_hours' => $task->estimated_hours,
                        'progress' => $task->progress,
                        'tags' => $request->tags ?? [],
                        'created_by' => [
                            'id' => $task->createdBy->id,
                            'name' => $task->createdBy->name
                        ],
                        'created_at' => $task->created_at->toISOString()
                    ]
                ],
                201
            );

        } catch (Exception $e) {
            Log::error('Error creating task', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->attributes->get('user_id'),
                'request_data' => $request->all()
            ]);

            return $this->errorResponse('Failed to create task', 500, 'TASK_CREATION_ERROR');
        }
    }

    /**
     * Display the specified task
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->attributes->get('user');

            // Get task with relationships
            $task = Task::with(['project', 'assignedUser', 'createdBy', 'photos'])
                ->findOrFail($id);

            // Verify access
            $userCompany = UserCompany::where('user_id', $user->id)
                ->where('company_id', $task->project->company_id)
                ->where('status', 'active')
                ->first();

            if (!$userCompany) {
                return $this->errorResponse('You do not have access to this task', 403, 'TASK_ACCESS_DENIED');
            }

            return $this->successResponse(
                'Task retrieved successfully',
                [
                    'task' => [
                        'id' => $task->id,
                        'code' => $task->code,
                        'title' => $task->title,
                        'description' => $task->description,
                        'project' => [
                            'id' => $task->project->id,
                            'name' => $task->project->name,
                            'code' => $task->project->code
                        ],
                        'assigned_to' => $task->assignedUser ? [
                            'id' => $task->assignedUser->id,
                            'name' => $task->assignedUser->name,
                            'email' => $task->assignedUser->email
                        ] : null,
                        'status' => $task->status,
                        'priority' => $task->priority,
                        'due_date' => $task->due_date ? $task->due_date->toISOString() : null,
                        'estimated_hours' => $task->estimated_hours,
                        'actual_hours' => $task->actual_hours,
                        'progress' => $task->progress,
                        'tags' => $task->tags ? json_decode($task->tags, true) : [],
                        'photos' => $task->photos->map(function ($photo) {
                            return [
                                'id' => $photo->id,
                                'filename' => $photo->filename,
                                'url' => Storage::url($photo->file_path),
                                'caption' => $photo->caption,
                                'created_at' => $photo->created_at->toISOString()
                            ];
                        }),
                        'created_by' => [
                            'id' => $task->createdBy->id,
                            'name' => $task->createdBy->name
                        ],
                        'created_at' => $task->created_at->toISOString(),
                        'updated_at' => $task->updated_at->toISOString()
                    ]
                ]
            );

        } catch (Exception $e) {
            Log::error('Error retrieving task', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->attributes->get('user_id'),
                'task_id' => $id
            ]);

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return $this->errorResponse('Task not found', 404, 'TASK_NOT_FOUND');
            }

            return $this->errorResponse('Failed to retrieve task', 500, 'TASK_RETRIEVAL_ERROR');
        }
    }

    /**
     * Update the specified task
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->attributes->get('user');

            // Validate request data
            $validator = Validator::make($request->all(), [
                'title' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'assigned_to' => 'nullable|integer|exists:users,id',
                'priority' => 'sometimes|string|in:low,medium,high,urgent',
                'status' => 'sometimes|string|in:pending,in_progress,completed,cancelled',
                'due_date' => 'nullable|date',
                'estimated_hours' => 'nullable|numeric|min:0',
                'actual_hours' => 'nullable|numeric|min:0',
                'progress' => 'nullable|integer|min:0|max:100',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:50'
            ], [
                'title.string' => 'Title must be a string',
                'title.max' => 'Title cannot exceed 255 characters',
                'priority.in' => 'Priority must be low, medium, high, or urgent',
                'status.in' => 'Status must be pending, in_progress, completed, or cancelled',
                'due_date.date' => 'Due date must be a valid date',
                'estimated_hours.numeric' => 'Estimated hours must be a number',
                'estimated_hours.min' => 'Estimated hours must be 0 or greater',
                'actual_hours.numeric' => 'Actual hours must be a number',
                'actual_hours.min' => 'Actual hours must be 0 or greater',
                'progress.integer' => 'Progress must be an integer',
                'progress.min' => 'Progress must be between 0 and 100',
                'progress.max' => 'Progress must be between 0 and 100',
                'tags.array' => 'Tags must be provided as an array',
                'tags.*.string' => 'Each tag must be a string',
                'tags.*.max' => 'Each tag cannot exceed 50 characters'
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            // Get task
            $task = Task::findOrFail($id);

            // Verify access
            $userCompany = UserCompany::where('user_id', $user->id)
                ->where('company_id', $task->project->company_id)
                ->where('status', 'active')
                ->first();

            if (!$userCompany) {
                return $this->errorResponse('You do not have access to this task', 403, 'TASK_ACCESS_DENIED');
            }

            // Update task
            $updateData = [];
            $fields = ['title', 'description', 'assigned_to', 'priority', 'status', 'due_date', 'estimated_hours', 'actual_hours', 'progress'];
            
            foreach ($fields as $field) {
                if ($request->has($field)) {
                    $updateData[$field] = $request->$field;
                }
            }

            if ($request->has('tags')) {
                $updateData['tags'] = json_encode($request->tags);
            }

            $task->update($updateData);

            // Load relationships
            $task->load(['project', 'assignedUser', 'createdBy']);

            return $this->successResponse(
                'Task updated successfully',
                [
                    'task' => [
                        'id' => $task->id,
                        'code' => $task->code,
                        'title' => $task->title,
                        'description' => $task->description,
                        'project' => [
                            'id' => $task->project->id,
                            'name' => $task->project->name,
                            'code' => $task->project->code
                        ],
                        'assigned_to' => $task->assignedUser ? [
                            'id' => $task->assignedUser->id,
                            'name' => $task->assignedUser->name,
                            'email' => $task->assignedUser->email
                        ] : null,
                        'priority' => $task->priority,
                        'status' => $task->status,
                        'due_date' => $task->due_date ? $task->due_date->toISOString() : null,
                        'estimated_hours' => $task->estimated_hours,
                        'actual_hours' => $task->actual_hours,
                        'progress' => $task->progress,
                        'tags' => $task->tags ? json_decode($task->tags, true) : [],
                        'updated_at' => $task->updated_at->toISOString()
                    ]
                ]
            );

        } catch (Exception $e) {
            Log::error('Error updating task', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->attributes->get('user_id'),
                'task_id' => $id,
                'request_data' => $request->all()
            ]);

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return $this->errorResponse('Task not found', 404, 'TASK_NOT_FOUND');
            }

            return $this->errorResponse('Failed to update task', 500, 'TASK_UPDATE_ERROR');
        }
    }

    /**
     * Remove the specified task
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->attributes->get('user');

            // Get task
            $task = Task::findOrFail($id);

            // Verify access
            $userCompany = UserCompany::where('user_id', $user->id)
                ->where('company_id', $task->project->company_id)
                ->where('status', 'active')
                ->first();

            if (!$userCompany) {
                return $this->errorResponse('You do not have access to this task', 403, 'TASK_ACCESS_DENIED');
            }

            // Soft delete task
            $task->delete();

            return $this->successResponse('Task deleted successfully');

        } catch (Exception $e) {
            Log::error('Error deleting task', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->attributes->get('user_id'),
                'task_id' => $id
            ]);

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return $this->errorResponse('Task not found', 404, 'TASK_NOT_FOUND');
            }

            return $this->errorResponse('Failed to delete task', 500, 'TASK_DELETION_ERROR');
        }
    }

    /**
     * Upload photos for a task
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadPhotos(Request $request, $id)
    {
        try {
            $user = $request->attributes->get('user');

            // Validate request
            $validator = Validator::make($request->all(), [
                'photos' => 'required|array',
                'photos.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
                'captions' => 'nullable|array',
                'captions.*' => 'nullable|string|max:255'
            ], [
                'photos.required' => 'Photos are required',
                'photos.array' => 'Photos must be provided as an array',
                'photos.*.required' => 'Each photo is required',
                'photos.*.image' => 'Each file must be an image',
                'photos.*.mimes' => 'Images must be in jpeg, png, jpg, or gif format',
                'photos.*.max' => 'Each image must be less than 5MB',
                'captions.array' => 'Captions must be provided as an array',
                'captions.*.string' => 'Each caption must be a string',
                'captions.*.max' => 'Each caption cannot exceed 255 characters'
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            // Get task
            $task = Task::findOrFail($id);

            // Verify access
            $userCompany = UserCompany::where('user_id', $user->id)
                ->where('company_id', $task->project->company_id)
                ->where('status', 'active')
                ->first();

            if (!$userCompany) {
                return $this->errorResponse('You do not have access to this task', 403, 'TASK_ACCESS_DENIED');
            }

            $uploadedPhotos = [];
            $photos = $request->file('photos');
            $captions = $request->get('captions', []);

            DB::beginTransaction();

            foreach ($photos as $index => $photo) {
                $filename = Str::uuid() . '.' . $photo->getClientOriginalExtension();
                $path = $photo->storeAs('tasks/' . $task->id . '/photos', $filename, 'public');

                $taskPhoto = TaskPhoto::create([
                    'task_id' => $task->id,
                    'filename' => $filename,
                    'original_filename' => $photo->getClientOriginalName(),
                    'file_path' => $path,
                    'file_size' => $photo->getSize(),
                    'mime_type' => $photo->getMimeType(),
                    'caption' => $captions[$index] ?? null,
                    'uploaded_by' => $user->id
                ]);

                $uploadedPhotos[] = [
                    'id' => $taskPhoto->id,
                    'filename' => $filename,
                    'url' => Storage::url($path),
                    'caption' => $taskPhoto->caption,
                    'created_at' => $taskPhoto->created_at->toISOString()
                ];
            }

            DB::commit();

            return $this->successResponse(
                'Photos uploaded successfully',
                ['photos' => $uploadedPhotos]
            );

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error uploading task photos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->attributes->get('user_id'),
                'task_id' => $id
            ]);

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return $this->errorResponse('Task not found', 404, 'TASK_NOT_FOUND');
            }

            return $this->errorResponse('Failed to upload photos', 500, 'PHOTO_UPLOAD_ERROR');
        }
    }

    /**
     * Helper function to return a successful response
     *
     * @param string $message
     * @param array $data
     * @param int $statusCode
     * @return \Illuminate\Http\JsonResponse
     */
    private function successResponse($message, $data = [], $statusCode = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    /**
     * Helper function to return an error response
     *
     * @param string $message
     * @param int $statusCode
     * @param string $errorCode
     * @return \Illuminate\Http\JsonResponse
     */
    private function errorResponse($message, $statusCode, $errorCode)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode
        ], $statusCode);
    }

    /**
     * Helper function to return a validation error response
     *
     * @param \Illuminate\Support\MessageBag $errors
     * @return \Illuminate\Http\JsonResponse
     */
    private function validateResponse($errors)
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors' => $errors
        ], 422);
    }
}
