<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inspection;
use App\Models\Project;
use App\Models\UserCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Exception;

class InspectionController extends Controller
{
    public function index(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_id' => 'required|exists:projects,id',
                'status' => 'nullable|in:scheduled,in_progress,completed,failed',
                'inspector_id' => 'nullable|exists:users,id',
                'page' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
            ], [
                'project_id.required' => 'Project ID is required',
                'project_id.exists' => 'Invalid project selected',
                'status.in' => 'Invalid status value',
                'inspector_id.exists' => 'Invalid inspector selected',
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

            $query = Inspection::with(['project', 'inspector', 'photos'])
                ->where('project_id', $request->project_id)
                ->where('is_deleted', 0);

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('inspector_id')) {
                $query->where('inspector_id', $request->inspector_id);
            }

            $limit = $request->get('limit', 10);
            $inspections = $query->orderBy('inspection_date', 'desc')
                ->paginate($limit);

            return $this->toJsonEnc($inspections, 'Inspections retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function store(Request $request)
    {
        try {
            // Step 3.1: Validation
            $validator = Validator::make($request->all(), [
                'project_id' => 'required|exists:projects,id',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'inspection_date' => 'required|date',
                'inspector_id' => 'required|exists:users,id',
                'category' => 'required|string|max:100',
                'priority' => 'required|in:low,medium,high,urgent',
                'status' => 'nullable|in:scheduled,in_progress,completed,failed',
                'notes' => 'nullable|string|max:2000',
                'checklist' => 'nullable|array',
                'photos' => 'nullable|array',
                'photos.*' => 'image|mimes:jpeg,png,jpg|max:2048',
            ], [
                'project_id.required' => 'Project ID is required',
                'project_id.exists' => 'Invalid project selected',
                'title.required' => 'Inspection title is required',
                'title.max' => 'Title cannot exceed 255 characters',
                'description.max' => 'Description cannot exceed 1000 characters',
                'inspection_date.required' => 'Inspection date is required',
                'inspection_date.date' => 'Invalid inspection date format',
                'inspector_id.required' => 'Inspector ID is required',
                'inspector_id.exists' => 'Invalid inspector selected',
                'category.required' => 'Category is required',
                'category.max' => 'Category cannot exceed 100 characters',
                'priority.required' => 'Priority is required',
                'priority.in' => 'Invalid priority value',
                'status.in' => 'Invalid status value',
                'notes.max' => 'Notes cannot exceed 2000 characters',
                'checklist.array' => 'Checklist must be an array',
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

            // Step 3.2: Create inspection
            $inspection = new Inspection();
            $inspection->project_id = $request->project_id;
            $inspection->title = $request->title;
            $inspection->description = $request->description;
            $inspection->inspection_date = $request->inspection_date;
            $inspection->inspector_id = $request->inspector_id;
            $inspection->category = $request->category;
            $inspection->priority = $request->priority;
            $inspection->status = $request->status ?? 'scheduled';
            $inspection->notes = $request->notes;
            $inspection->checklist = $request->checklist ?? [];
            $inspection->code = $this->generateInspectionCode();
            $inspection->is_deleted = false;

            $inspection->save();

            // Handle photo uploads if provided
            if ($request->hasFile('photos')) {
                foreach ($request->file('photos') as $photo) {
                    $photoPath = $photo->store('inspection_photos', 'public');
                    
                    // Create photo record (assuming Photo model exists)
                    $inspection->photos()->create([
                        'file_path' => $photoPath,
                        'file_type' => $photo->getClientOriginalExtension(),
                        'file_size' => $photo->getSize(),
                        'uploaded_by' => $request->user_id,
                    ]);
                }
            }

            return $this->toJsonEnc($inspection->load(['project', 'inspector', 'photos']), 'Inspection created successfully', '201');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $inspection = Inspection::with(['project', 'inspector', 'photos', 'snagReports'])
                ->where('id', $id)
                ->where('is_deleted', 0)
                ->first();

            if (!$inspection) {
                return $this->toJsonEnc([], 'Inspection not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $inspection->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            return $this->toJsonEnc($inspection, 'Inspection retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'inspection_date' => 'sometimes|required|date',
                'inspector_id' => 'sometimes|required|exists:users,id',
                'category' => 'sometimes|required|string|max:100',
                'priority' => 'sometimes|required|in:low,medium,high,urgent',
                'status' => 'sometimes|required|in:scheduled,in_progress,completed,failed',
                'notes' => 'nullable|string|max:2000',
                'checklist' => 'nullable|array',
                'photos' => 'nullable|array',
                'photos.*' => 'image|mimes:jpeg,png,jpg|max:2048',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $inspection = Inspection::find($id);

            if (!$inspection) {
                return $this->toJsonEnc([], 'Inspection not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $inspection->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            $updateData = $request->only([
                'title', 'description', 'inspection_date', 'inspector_id',
                'category', 'priority', 'status', 'notes', 'checklist'
            ]);

            if ($request->has('checklist')) {
                $updateData['checklist'] = $request->checklist;
            }

            $inspection->update($updateData);

            // Handle new photo uploads
            if ($request->hasFile('photos')) {
                foreach ($request->file('photos') as $photo) {
                    $photoPath = $photo->store('inspection_photos', 'public');
                    
                    $inspection->photos()->create([
                        'file_path' => $photoPath,
                        'file_type' => $photo->getClientOriginalExtension(),
                        'file_size' => $photo->getSize(),
                        'uploaded_by' => $request->user_id,
                    ]);
                }
            }

            return $this->toJsonEnc($inspection->load(['project', 'inspector', 'photos']), 'Inspection updated successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $inspection = Inspection::find($id);

            if (!$inspection) {
                return $this->toJsonEnc([], 'Inspection not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $inspection->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            // Soft delete
            $inspection->update(['is_deleted' => true]);

            return $this->toJsonEnc([], 'Inspection deleted successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:scheduled,in_progress,completed,failed',
                'notes' => 'nullable|string|max:2000',
            ], [
                'status.required' => 'Status is required',
                'status.in' => 'Invalid status value',
                'notes.max' => 'Notes cannot exceed 2000 characters',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $inspection = Inspection::find($id);

            if (!$inspection) {
                return $this->toJsonEnc([], 'Inspection not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $inspection->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            $updateData = ['status' => $request->status];
            if ($request->has('notes')) {
                $updateData['notes'] = $request->notes;
            }

            $inspection->update($updateData);

            return $this->toJsonEnc($inspection->load(['project', 'inspector']), 'Inspection status updated successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    private function generateInspectionCode()
    {
        $code = 'INS' . strtoupper(Str::random(6));
        
        while (Inspection::where('code', $code)->exists()) {
            $code = 'INS' . strtoupper(Str::random(6));
        }

        return $code;
    }
}
