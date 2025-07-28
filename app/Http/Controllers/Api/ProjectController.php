<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Company;
use App\Models\UserCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Exception;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'status' => 'nullable|in:active,inactive,completed',
                'search' => 'nullable|string|max:255',
                'page' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
            ], [
                'company_id.required' => 'Company ID is required',
                'company_id.exists' => 'Invalid company selected',
                'status.in' => 'Invalid status value',
                'search.string' => 'Search must be a string',
                'search.max' => 'Search cannot exceed 255 characters',
                'page.integer' => 'Page must be an integer',
                'page.min' => 'Page must be at least 1',
                'limit.integer' => 'Limit must be an integer',
                'limit.min' => 'Limit must be at least 1',
                'limit.max' => 'Limit cannot exceed 100',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $request->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            $query = Project::with(['company', 'tasks', 'documents', 'photos'])
                ->where('company_id', $request->company_id)
                ->where('is_deleted', 0);

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('location', 'like', "%{$search}%");
                });
            }

            $limit = $request->get('limit', 10);
            $projects = $query->orderBy('created_at', 'desc')
                ->paginate($limit);

            return $this->toJsonEnc($projects, 'Projects retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function store(Request $request)
    {
        try {
            // Step 3.1: Validation
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'location' => 'required|string|max:500',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
                'budget' => 'nullable|numeric|min:0',
                'client_name' => 'nullable|string|max:255',
                'client_contact' => 'nullable|string|max:20',
                'client_email' => 'nullable|email|max:255',
                'status' => 'nullable|in:planning,in_progress,on_hold,completed,cancelled',
                'priority' => 'nullable|in:low,medium,high,urgent',
                'cover_image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            ], [
                'company_id.required' => 'Company ID is required',
                'company_id.exists' => 'Invalid company selected',
                'name.required' => 'Project name is required',
                'name.max' => 'Project name cannot exceed 255 characters',
                'description.max' => 'Description cannot exceed 1000 characters',
                'location.required' => 'Project location is required',
                'location.max' => 'Location cannot exceed 500 characters',
                'start_date.required' => 'Start date is required',
                'start_date.date' => 'Invalid start date format',
                'end_date.required' => 'End date is required',
                'end_date.date' => 'Invalid end date format',
                'end_date.after' => 'End date must be after start date',
                'budget.numeric' => 'Budget must be a number',
                'budget.min' => 'Budget must be positive',
                'client_name.max' => 'Client name cannot exceed 255 characters',
                'client_contact.max' => 'Client contact cannot exceed 20 characters',
                'client_email.email' => 'Please provide a valid client email',
                'client_email.max' => 'Client email cannot exceed 255 characters',
                'status.in' => 'Invalid status value',
                'priority.in' => 'Invalid priority value',
                'cover_image.image' => 'Cover image must be an image file',
                'cover_image.mimes' => 'Cover image must be in JPEG, PNG, or JPG format',
                'cover_image.max' => 'Cover image file size cannot exceed 2MB',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            // Check if user has admin/manager access to this company
            $userCompany = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $request->company_id)
                ->whereIn('role', ['admin', 'manager'])
                ->where('status', 'active')
                ->first();

            if (!$userCompany) {
                return $this->toJsonEnc([], 'Admin or manager access required', '403');
            }

            // Step 3.2: Create project
            $project = new Project();
            $project->company_id = $request->company_id;
            $project->name = $request->name;
            $project->description = $request->description;
            $project->location = $request->location;
            $project->start_date = $request->start_date;
            $project->end_date = $request->end_date;
            $project->budget = $request->budget ?? 0;
            $project->client_name = $request->client_name;
            $project->client_contact = $request->client_contact;
            $project->client_email = $request->client_email;
            $project->status = $request->status ?? 'planning';
            $project->priority = $request->priority ?? 'medium';
            $project->progress = 0;
            $project->code = $this->generateProjectCode();
            $project->is_deleted = false;

            if ($request->hasFile('cover_image')) {
                $imagePath = $request->file('cover_image')->store('project_covers', 'public');
                $project->cover_image = $imagePath;
            }

            $project->save();

            return $this->toJsonEnc($project->load(['company', 'tasks']), 'Project created successfully', '201');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $project = Project::with([
                'company',
                'tasks' => function ($query) {
                    $query->where('is_deleted', 0);
                },
                'tasks.assignedUser',
                'documents' => function ($query) {
                    $query->where('is_deleted', 0);
                },
                'photos' => function ($query) {
                    $query->where('is_deleted', 0);
                },
                'inspections' => function ($query) {
                    $query->where('is_deleted', 0);
                },
                'snagReports' => function ($query) {
                    $query->where('is_deleted', 0);
                }
            ])
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->first();

            if (!$project) {
                return $this->toJsonEnc([], 'Project not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            return $this->toJsonEnc($project, 'Project retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'location' => 'sometimes|required|string|max:500',
                'start_date' => 'sometimes|required|date',
                'end_date' => 'sometimes|required|date|after:start_date',
                'budget' => 'nullable|numeric|min:0',
                'client_name' => 'nullable|string|max:255',
                'client_contact' => 'nullable|string|max:20',
                'client_email' => 'nullable|email|max:255',
                'status' => 'sometimes|required|in:planning,in_progress,on_hold,completed,cancelled',
                'priority' => 'sometimes|required|in:low,medium,high,urgent',
                'cover_image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $project = Project::find($id);

            if (!$project) {
                return $this->toJsonEnc([], 'Project not found', '404');
            }

            // Check if user has admin/manager access to this company
            $userCompany = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $project->company_id)
                ->whereIn('role', ['admin', 'manager'])
                ->where('status', 'active')
                ->first();

            if (!$userCompany) {
                return $this->toJsonEnc([], 'Admin or manager access required', '403');
            }

            $updateData = $request->only([
                'name', 'description', 'location', 'start_date', 'end_date',
                'budget', 'client_name', 'client_contact', 'client_email',
                'status', 'priority'
            ]);

            if ($request->hasFile('cover_image')) {
                $imagePath = $request->file('cover_image')->store('project_covers', 'public');
                $updateData['cover_image'] = $imagePath;
            }

            $project->update($updateData);

            return $this->toJsonEnc($project->load(['company', 'tasks']), 'Project updated successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $project = Project::find($id);

            if (!$project) {
                return $this->toJsonEnc([], 'Project not found', '404');
            }

            // Check if user has admin/manager access to this company
            $userCompany = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $project->company_id)
                ->whereIn('role', ['admin', 'manager'])
                ->where('status', 'active')
                ->first();

            if (!$userCompany) {
                return $this->toJsonEnc([], 'Admin or manager access required', '403');
            }

            // Soft delete
            $project->update(['is_deleted' => true]);

            return $this->toJsonEnc([], 'Project deleted successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function updateProgress(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'progress' => 'required|numeric|min:0|max:100',
            ], [
                'progress.required' => 'Progress value is required',
                'progress.numeric' => 'Progress must be a number',
                'progress.min' => 'Progress must be at least 0',
                'progress.max' => 'Progress cannot exceed 100',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $project = Project::find($id);

            if (!$project) {
                return $this->toJsonEnc([], 'Project not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            $project->update(['progress' => $request->progress]);

            // Update project status based on progress
            if ($request->progress == 100) {
                $project->update(['status' => 'completed']);
            } elseif ($request->progress > 0) {
                $project->update(['status' => 'in_progress']);
            }

            return $this->toJsonEnc($project, 'Project progress updated successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function getProjectStats(Request $request, $id)
    {
        try {
            $project = Project::with(['tasks' => function ($query) {
                $query->where('is_deleted', 0);
            }])
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->first();

            if (!$project) {
                return $this->toJsonEnc([], 'Project not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            $stats = [
                'total_tasks' => $project->tasks->count(),
                'completed_tasks' => $project->tasks->where('status', 'completed')->count(),
                'pending_tasks' => $project->tasks->where('status', 'pending')->count(),
                'in_progress_tasks' => $project->tasks->where('status', 'in_progress')->count(),
                'overdue_tasks' => $project->tasks->where('due_date', '<', now())->count(),
                'total_budget' => $project->budget,
                'project_progress' => $project->progress,
                'project_status' => $project->status,
            ];

            return $this->toJsonEnc($stats, 'Project statistics retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    private function generateProjectCode()
    {
        $code = 'PRJ' . strtoupper(Str::random(6));
        
        while (Project::where('code', $code)->exists()) {
            $code = 'PRJ' . strtoupper(Str::random(6));
        }

        return $code;
    }
}
