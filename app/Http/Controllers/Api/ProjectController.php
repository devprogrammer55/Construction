<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Models\SnagReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $projects = Project::with(['company', 'projectManager', 'client'])
            ->whereHas('company', function ($query) use ($user) {
                $query->whereHas('users', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                });
            })
            ->when($request->status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $projects
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'client_id' => 'nullable|exists:users,id',
            'project_manager_id' => 'nullable|exists:users,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'budget' => 'nullable|numeric|min:0',
            'address' => 'required|string|max:500',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'project_type' => 'required|string|max:255',
            'status' => 'required|in:planning,in_progress,on_hold,completed,cancelled',
            'priority' => 'required|in:low,medium,high,critical',
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $company = $user->currentCompany;

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'No company selected'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $project = Project::create([
                'company_id' => $company->id,
                'code' => Project::generateCode(),
                'name' => $request->name,
                'description' => $request->description,
                'client_id' => $request->client_id,
                'project_manager_id' => $request->project_manager_id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'budget' => $request->budget,
                'address' => $request->address,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'project_type' => $request->project_type,
                'status' => $request->status,
                'priority' => $request->priority,
                'progress' => 0,
                'metadata' => [],
            ]);

            if ($request->hasFile('cover_image')) {
                $coverPath = $request->file('cover_image')->store('project_covers', 'public');
                $project->update(['cover_image' => $coverPath]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Project created successfully',
                'data' => $project->load(['company', 'projectManager', 'client'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create project',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, Project $project)
    {
        $project->load([
            'company',
            'projectManager',
            'client',
            'activities.tasks',
            'tasks.assignedTo',
            'tasks.photos',
            'snagReports',
            'documents',
            'photos',
            'photoAlbums'
        ]);

        return response()->json([
            'success' => true,
            'data' => $project
        ]);
    }
