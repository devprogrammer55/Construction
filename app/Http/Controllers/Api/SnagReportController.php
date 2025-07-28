<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SnagReport;
use App\Models\Project;
use App\Models\UserCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Exception;

class SnagReportController extends Controller
{
    public function index(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_id' => 'required|exists:projects,id',
                'status' => 'nullable|in:reported,in_progress,resolved,rejected',
                'priority' => 'nullable|in:low,medium,high,critical',
                'category' => 'nullable|string|max:100',
                'reported_by' => 'nullable|exists:users,id',
                'assigned_to' => 'nullable|exists:users,id',
                'page' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
            ], [
                'project_id.required' => 'Project ID is required',
                'project_id.exists' => 'Invalid project selected',
                'status.in' => 'Invalid status value',
                'priority.in' => 'Invalid priority value',
                'category.max' => 'Category cannot exceed 100 characters',
                'reported_by.exists' => 'Invalid reporter selected',
                'assigned_to.exists' => 'Invalid assignee selected',
                'page.integer' => 'Page must be an integer',
                'page.min' => 'Page must be at least 1',
                'limit.integer' => 'Limit must be an integer',
                'limit.min' => 'Limit must be at least 1',
                'limit.max' => 'Limit cannot exceed 100',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $project = Project::find($request->project_id);

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

            $query = SnagReport::with(['project', 'reporter', 'assignee', 'photos'])
                ->where('project_id', $request->project_id)
                ->where('is_deleted', 0);

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('priority')) {
                $query->where('priority', $request->priority);
            }

            if ($request->has('category')) {
                $query->where('category', $request->category);
            }

            if ($request->has('reported_by')) {
                $query->where('reported_by', $request->reported_by);
            }

            if ($request->has('assigned_to')) {
                $query->where('assigned_to', $request->assigned_to);
            }

            $limit = $request->get('limit', 10);
            $snagReports = $query->orderBy('created_at', 'desc')
                ->paginate($limit);

            return $this->toJsonEnc($snagReports, 'Snag reports retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_id' => 'required|exists:projects,id',
                'title' => 'required|string|max:255',
                'description' => 'required|string|max:2000',
                'location' => 'required|string|max:500',
                'category' => 'required|string|max:100',
                'priority' => 'required|in:low,medium,high,critical',
                'severity' => 'required|in:minor,major,critical',
                'assigned_to' => 'nullable|exists:users,id',
                'due_date' => 'nullable|date',
                'photos' => 'nullable|array',
                'photos.*' => 'image|mimes:jpeg,png,jpg|max:2048',
            ], [
                'project_id.required' => 'Project ID is required',
                'project_id.exists' => 'Invalid project selected',
                'title.required' => 'Snag title is required',
                'title.max' => 'Title cannot exceed 255 characters',
                'description.required' => 'Description is required',
                'description.max' => 'Description cannot exceed 2000 characters',
                'location.required' => 'Location is required',
                'location.max' => 'Location cannot exceed 500 characters',
                'category.required' => 'Category is required',
                'category.max' => 'Category cannot exceed 100 characters',
                'priority.required' => 'Priority is required',
                'priority.in' => 'Invalid priority value',
                'severity.required' => 'Severity is required',
                'severity.in' => 'Invalid severity value',
                'assigned_to.exists' => 'Invalid assignee selected',
                'due_date.date' => 'Invalid due date format',
                'photos.array' => 'Photos must be an array',
                'photos.*.image' => 'Each photo must be an image file',
                'photos.*.mimes' => 'Photos must be in JPEG, PNG, or JPG format',
                'photos.*.max' => 'Each photo file size cannot exceed 2MB',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $project = Project::find($request->project_id);

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

            // Create snag report
            $snagReport = new SnagReport();
            $snagReport->project_id = $request->project_id;
            $snagReport->title = $request->title;
            $snagReport->description = $request->description;
            $snagReport->location = $request->location;
            $snagReport->category = $request->category;
            $snagReport->priority = $request->priority;
            $snagReport->severity = $request->severity;
            $snagReport->status = 'reported';
            $snagReport->reported_by = $request->user_id;
            $snagReport->assigned_to = $request->assigned_to;
            $snagReport->due_date = $request->due_date;
            $snagReport->code = $this->generateSnagCode();
            $snagReport->is_deleted = false;

            $snagReport->save();

            // Handle photo uploads if provided
            if ($request->hasFile('photos')) {
                foreach ($request->file('photos') as $photo) {
                    $photoPath = $photo->store('snag_photos', 'public');
                    
                    $snagReport->photos()->create([
                        'file_path' => $photoPath,
                        'file_type' => $photo->getClientOriginalExtension(),
                        'file_size' => $photo->getSize(),
                        'uploaded_by' => $request->user_id,
                    ]);
                }
            }

            return $this->toJsonEnc($snagReport->load(['project', 'reporter', 'assignee', 'photos']), 'Snag report created successfully', '201');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $snagReport = SnagReport::with(['project', 'reporter', 'assignee', 'photos'])
                ->where('id', $id)
                ->where('is_deleted', 0)
                ->first();

            if (!$snagReport) {
                return $this->toJsonEnc([], 'Snag report not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $snagReport->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            return $this->toJsonEnc($snagReport, 'Snag report retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string|max:2000',
                'location' => 'nullable|string|max:500',
                'category' => 'sometimes|required|string|max:100',
                'priority' => 'sometimes|required|in:low,medium,high,critical',
                'severity' => 'sometimes|required|in:minor,major,critical',
                'assigned_to' => 'nullable|exists:users,id',
                'due_date' => 'nullable|date',
                'photos' => 'nullable|array',
                'photos.*' => 'image|mimes:jpeg,png,jpg|max:2048',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $snagReport = SnagReport::find($id);

            if (!$snagReport) {
                return $this->toJsonEnc([], 'Snag report not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $snagReport->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            $updateData = $request->only([
                'title', 'description', 'location', 'category', 'priority',
                'severity', 'assigned_to', 'due_date'
            ]);

            $snagReport->update($updateData);

            // Handle new photo uploads
            if ($request->hasFile('photos')) {
                foreach ($request->file('photos') as $photo) {
                    $photoPath = $photo->store('snag_photos', 'public');
                    
                    $snagReport->photos()->create([
                        'file_path' => $photoPath,
                        'file_type' => $photo->getClientOriginalExtension(),
                        'file_size' => $photo->getSize(),
                        'uploaded_by' => $request->user_id,
                    ]);
                }
            }

            return $this->toJsonEnc($snagReport->load(['project', 'reporter', 'assignee', 'photos']), 'Snag report updated successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $snagReport = SnagReport::find($id);

            if (!$snagReport) {
                return $this->toJsonEnc([], 'Snag report not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $snagReport->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            // Soft delete
            $snagReport->update(['is_deleted' => true]);

            return $this->toJsonEnc([], 'Snag report deleted successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:reported,in_progress,resolved,rejected',
                'notes' => 'nullable|string|max:2000',
            ], [
                'status.required' => 'Status is required',
                'status.in' => 'Invalid status value',
                'notes.max' => 'Notes cannot exceed 2000 characters',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $snagReport = SnagReport::find($id);

            if (!$snagReport) {
                return $this->toJsonEnc([], 'Snag report not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $snagReport->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            $updateData = ['status' => $request->status];
            if ($request->has('notes')) {
                $updateData['notes'] = $request->notes;
            }

            $snagReport->update($updateData);

            return $this->toJsonEnc($snagReport->load(['project', 'reporter', 'assignee']), 'Snag report status updated successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function assign(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'assigned_to' => 'required|exists:users,id',
            ], [
                'assigned_to.required' => 'Assignee is required',
                'assigned_to.exists' => 'Invalid assignee selected',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $snagReport = SnagReport::find($id);

            if (!$snagReport) {
                return $this->toJsonEnc([], 'Snag report not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $snagReport->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            $snagReport->update([
                'assigned_to' => $request->assigned_to,
                'status' => 'in_progress'
            ]);

            return $this->toJsonEnc($snagReport->load(['project', 'reporter', 'assignee']), 'Snag report assigned successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    private function generateSnagCode()
    {
        $code = 'SNAG' . strtoupper(Str::random(6));
        
        while (SnagReport::where('code', $code)->exists()) {
            $code = 'SNAG' . strtoupper(Str::random(6));
        }

        return $code;
    }
}
