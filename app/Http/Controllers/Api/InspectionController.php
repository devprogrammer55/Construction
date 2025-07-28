<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inspection;
use App\Models\InspectionPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InspectionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $inspections = Inspection::with(['project', 'task', 'inspector'])
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
            ->when($request->task_id, function ($query, $taskId) {
                return $query->where('task_id', $taskId);
            })
            ->when($request->inspector_id, function ($query, $inspectorId) {
                return $query->where('inspector_id', $inspectorId);
            })
            ->when($request->status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->category, function ($query, $category) {
                return $query->where('category', $category);
            })
            ->when($request->priority, function ($query, $priority) {
                return $query->where('priority', $priority);
            })
            ->when($request->search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('scheduled_date', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $inspections
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
            'inspector_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|in:safety,quality,compliance,progress,final,pre_delivery',
            'scheduled_date' => 'required|date',
            'priority' => 'required|in:low,medium,high,critical',
            'notes' => 'nullable|string',
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
            $inspection = Inspection::create([
                'project_id' => $request->project_id,
                'task_id' => $request->task_id,
                'inspector_id' => $request->inspector_id,
                'title' => $request->title,
                'description' => $request->description,
                'category' => $request->category,
                'scheduled_date' => $request->scheduled_date,
                'status' => 'scheduled',
                'priority' => $request->priority,
                'notes' => $request->notes,
                'findings' => null,
                'recommendations' => null,
                'score' => null,
                'metadata' => [],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inspection scheduled successfully',
                'data' => $inspection->load(['project', 'task', 'inspector'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to schedule inspection',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, Inspection $inspection)
    {
        $inspection->load([
            'project',
            'task',
            'inspector',
            'photos'
        ]);

        return response()->json([
            'success' => true,
            'data' => $inspection
        ]);
    }

    public function update(Request $request, Inspection $inspection)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'category' => 'sometimes|in:safety,quality,compliance,progress,final,pre_delivery',
            'scheduled_date' => 'sometimes|date',
            'priority' => 'sometimes|in:low,medium,high,critical',
            'notes' => 'nullable|string',
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
            $inspection->update($request->all());
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inspection updated successfully',
                'data' => $inspection->load(['project', 'task', 'inspector'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update inspection',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, Inspection $inspection)
    {
        DB::beginTransaction();
        try {
            // Delete associated photos
            foreach ($inspection->photos as $photo) {
                if ($photo->file_path) {
                    Storage::disk('public')->delete($photo->file_path);
                }
                $photo->delete();
            }

            $inspection->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inspection deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete inspection',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function complete(Request $request, Inspection $inspection)
    {
        $validator = Validator::make($request->all(), [
            'findings' => 'required|string',
            'recommendations' => 'nullable|string',
            'score' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
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
            $inspection->markAsCompleted([
                'findings' => $request->findings,
                'recommendations' => $request->recommendations,
                'score' => $request->score,
                'notes' => $request->notes,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inspection completed successfully',
                'data' => $inspection->load(['project', 'task', 'inspector'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete inspection',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, Inspection $inspection)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:scheduled,in_progress,completed,failed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $inspection->update(['status' => $request->status]);

        if ($request->status === 'completed') {
            $inspection->markAsCompleted();
        }

        return response()->json([
            'success' => true,
            'message' => 'Inspection status updated successfully',
            'data' => $inspection
        ]);
    }

    public function uploadPhotos(Request $request, Inspection $inspection)
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
                $path = $photo->store('inspection_photos', 'public');
                
                $inspectionPhoto = InspectionPhoto::create([
                    'inspection_id' => $inspection->id,
                    'file_path' => $path,
                    'file_name' => $photo->getClientOriginalName(),
                    'file_size' => $photo->getSize(),
                    'mime_type' => $photo->getMimeType(),
                    'description' => $request->descriptions[$index] ?? null,
                    'uploaded_by' => $request->user()->id,
                ]);

                $uploadedPhotos[] = $inspectionPhoto;
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

    public function getPhotos(Request $request, Inspection $inspection)
    {
        $photos = $inspection->photos()->with('uploadedBy')->get();

        return response()->json([
            'success' => true,
            'data' => $photos
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
            'total_inspections' => Inspection::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->count(),
            'scheduled_inspections' => Inspection::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('status', 'scheduled')->count(),
            'completed_inspections' => Inspection::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('status', 'completed')->count(),
            'failed_inspections' => Inspection::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('status', 'failed')->count(),
            'overdue_inspections' => Inspection::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('scheduled_date', '<', now())
              ->whereNotIn('status', ['completed', 'cancelled'])
              ->count(),
            'inspections_by_category' => Inspection::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->select('category', DB::raw('count(*) as count'))
              ->groupBy('category')
              ->pluck('count', 'category'),
            'average_score' => Inspection::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->whereNotNull('score')->avg('score'),
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }
}
