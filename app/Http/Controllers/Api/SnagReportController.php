<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SnagReport;
use App\Models\SnagReportPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SnagReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $snagReports = SnagReport::with([
            'project', 
            'task', 
            'reportedBy', 
            'assignedTo', 
            'resolvedBy',
            'photos'
        ])
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
        ->when($request->status, function ($query, $status) {
            return $query->where('status', $status);
        })
        ->when($request->severity, function ($query, $severity) {
            return $query->where('severity', $severity);
        })
        ->when($request->assigned_to, function ($query, $assignedTo) {
            return $query->where('assigned_to', $assignedTo);
        })
        ->when($request->reported_by, function ($query, $reportedBy) {
            return $query->where('reported_by', $reportedBy);
        })
        ->when($request->search, function ($query, $search) {
            return $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        })
        ->orderBy('created_at', 'desc')
        ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $snagReports
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'severity' => 'required|in:low,medium,high,critical',
            'priority' => 'required|in:low,medium,high,critical',
            'due_date' => 'required|date',
            'assigned_to' => 'nullable|exists:users,id',
            'location' => 'nullable|string|max:255',
            'estimated_cost' => 'nullable|numeric|min:0',
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
            $snagReport = SnagReport::create([
                'project_id' => $request->project_id,
                'task_id' => $request->task_id,
                'title' => $request->title,
                'description' => $request->description,
                'severity' => $request->severity,
                'priority' => $request->priority,
                'status' => 'open',
                'due_date' => $request->due_date,
                'assigned_to' => $request->assigned_to,
                'reported_by' => $request->user()->id,
                'location' => $request->location,
                'estimated_cost' => $request->estimated_cost ?? 0,
                'actual_cost' => 0,
                'tags' => $request->tags ?? [],
                'metadata' => [],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Snag report created successfully',
                'data' => $snagReport->load([
                    'project', 
                    'task', 
                    'reportedBy', 
                    'assignedTo'
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create snag report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, SnagReport $snagReport)
    {
        $snagReport->load([
            'project',
            'task',
            'reportedBy',
            'assignedTo',
            'resolvedBy',
            'photos'
        ]);

        return response()->json([
            'success' => true,
            'data' => $snagReport
        ]);
    }

    public function update(Request $request, SnagReport $snagReport)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'severity' => 'sometimes|in:low,medium,high,critical',
            'priority' => 'sometimes|in:low,medium,high,critical',
            'due_date' => 'sometimes|date',
            'assigned_to' => 'nullable|exists:users,id',
            'location' => 'nullable|string|max:255',
            'estimated_cost' => 'nullable|numeric|min:0',
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
            $snagReport->update($request->all());
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Snag report updated successfully',
                'data' => $snagReport->load([
                    'project', 
                    'task', 
                    'reportedBy', 
                    'assignedTo'
                ])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update snag report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, SnagReport $snagReport)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:open,in_progress,resolved,closed,rejected',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $updateData = ['status' => $request->status];

        if ($request->status === 'resolved') {
            $updateData['resolved_at'] = now();
            $updateData['resolved_by'] = $request->user()->id;
        } elseif ($request->status === 'closed') {
            $updateData['closed_at'] = now();
        }

        $snagReport->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Snag report status updated successfully',
            'data' => $snagReport->load([
                'project', 
                'task', 
                'reportedBy', 
                'assignedTo', 
                'resolvedBy'
            ])
        ]);
    }

    public function assignUser(Request $request, SnagReport $snagReport)
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

        $snagReport->update(['assigned_to' => $request->user_id]);

        return response()->json([
            'success' => true,
            'message' => 'Snag report assigned successfully',
            'data' => $snagReport->load([
                'project', 
                'task', 
                'reportedBy', 
                'assignedTo'
            ])
        ]);
    }

    public function uploadPhotos(Request $request, SnagReport $snagReport)
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
                $path = $photo->store('snag_photos', 'public');
                
                $snagPhoto = SnagReportPhoto::create([
                    'snag_report_id' => $snagReport->id,
                    'file_path' => $path,
                    'file_name' => $photo->getClientOriginalName(),
                    'file_size' => $photo->getSize(),
                    'mime_type' => $photo->getMimeType(),
                    'description' => $request->descriptions[$index] ?? null,
                    'uploaded_by' => $request->user()->id,
                ]);

                $uploadedPhotos[] = $snagPhoto;
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

    public function getPhotos(Request $request, SnagReport $snagReport)
    {
        $photos = $snagReport->photos()->with('uploadedBy')->get();

        return response()->json([
            'success' => true,
            'data' => $photos
        ]);
    }

    public function destroy(Request $request, SnagReport $snagReport)
    {
        DB::beginTransaction();
        try {
            // Delete associated photos
            foreach ($snagReport->photos as $photo) {
                if ($photo->file_path) {
                    Storage::disk('public')->delete($photo->file_path);
                }
                $photo->delete();
            }

            $snagReport->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Snag report deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete snag report',
                'error' => $e->getMessage()
            ], 500);
        }
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
            'total_snags' => SnagReport::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->count(),
            'open_snags' => SnagReport::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('status', 'open')->count(),
            'in_progress_snags' => SnagReport::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('status', 'in_progress')->count(),
            'resolved_snags' => SnagReport::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('status', 'resolved')->count(),
            'closed_snags' => SnagReport::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('status', 'closed')->count(),
            'overdue_snags' => SnagReport::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->where('due_date', '<', now())
              ->whereNotIn('status', ['resolved', 'closed'])
              ->count(),
            'snags_by_severity' => SnagReport::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->select('severity', DB::raw('count(*) as count'))
              ->groupBy('severity')
              ->pluck('count', 'severity'),
            'total_estimated_cost' => SnagReport::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->sum('estimated_cost'),
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }
}
