<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $documents = Document::with(['project', 'uploadedBy', 'versions'])
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
            ->when($request->category, function ($query, $category) {
                return $query->where('category', $category);
            })
            ->when($request->search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->currentVersion()
            ->orderBy('updated_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $documents
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|exists:projects,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'document' => 'required|file|max:10240', // 10MB max
            'category' => 'required|in:plan,blueprint,specification,contract,report,permit,other',
            'version_notes' => 'nullable|string|max:500',
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
            $file = $request->file('document');
            $path = $file->store('documents', 'public');
            
            $version = Document::getNextVersionNumber($request->project_id, $request->name);

            // Mark previous versions as not current
            Document::where('project_id', $request->project_id)
                ->where('name', $request->name)
                ->update(['is_current_version' => false]);

            $document = Document::create([
                'project_id' => $request->project_id,
                'name' => $request->name,
                'description' => $request->description,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'category' => $request->category,
                'version' => $version,
                'version_notes' => $request->version_notes ?? 'Initial version',
                'uploaded_by' => $request->user()->id,
                'is_current_version' => true,
                'metadata' => [],
            ]);

            // Create first version record
            DocumentVersion::create([
                'document_id' => $document->id,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'version' => $version,
                'version_notes' => $request->version_notes ?? 'Initial version',
                'uploaded_by' => $request->user()->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document uploaded successfully',
                'data' => $document->load(['project', 'uploadedBy'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Clean up uploaded file if transaction failed
            if (isset($path)) {
                Storage::disk('public')->delete($path);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, Document $document)
    {
        $document->load(['project', 'uploadedBy', 'versions.uploadedBy']);

        return response()->json([
            'success' => true,
            'data' => $document
        ]);
    }

    public function update(Request $request, Document $document)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'category' => 'sometimes|in:plan,blueprint,specification,contract,report,permit,other',
            'version_notes' => 'nullable|string|max:500',
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
            $document->update($request->only(['name', 'description', 'category', 'version_notes']));
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document updated successfully',
                'data' => $document->load(['project', 'uploadedBy'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function uploadNewVersion(Request $request, Document $document)
    {
        $validator = Validator::make($request->all(), [
            'document' => 'required|file|max:10240', // 10MB max
            'version_notes' => 'nullable|string|max:500',
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
            $file = $request->file('document');
            $path = $file->store('documents', 'public');
            
            $newVersion = $document->version + 1;

            // Mark current document as not current
            $document->update(['is_current_version' => false]);

            // Create new document record for the new version
            $newDocument = Document::create([
                'project_id' => $document->project_id,
                'name' => $document->name,
                'description' => $document->description,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'category' => $document->category,
                'version' => $newVersion,
                'version_notes' => $request->version_notes ?? 'Version ' . $newVersion,
                'uploaded_by' => $request->user()->id,
                'is_current_version' => true,
                'metadata' => $document->metadata,
            ]);

            // Create version record
            DocumentVersion::create([
                'document_id' => $newDocument->id,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'version' => $newVersion,
                'version_notes' => $request->version_notes ?? 'Version ' . $newVersion,
                'uploaded_by' => $request->user()->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'New version uploaded successfully',
                'data' => $newDocument->load(['project', 'uploadedBy'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            if (isset($path)) {
                Storage::disk('public')->delete($path);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload new version',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getVersions(Request $request, Document $document)
    {
        $versions = $document->versions()->with('uploadedBy')->orderBy('version', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $versions
        ]);
    }

    public function download(Request $request, Document $document)
    {
        if (!Storage::disk('public')->exists($document->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'File not found'
            ], 404);
        }

        return response()->download(
            storage_path('app/public/' . $document->file_path),
            $document->file_name
        );
    }

    public function destroy(Request $request, Document $document)
    {
        DB::beginTransaction();
        try {
            // Delete all versions
            foreach ($document->versions as $version) {
                if ($version->file_path) {
                    Storage::disk('public')->delete($version->file_path);
                }
                $version->delete();
            }

            // Delete main file
            if ($document->file_path) {
                Storage::disk('public')->delete($document->file_path);
            }

            $document->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document',
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
            'total_documents' => Document::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->currentVersion()->count(),
            'documents_by_category' => Document::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->currentVersion()
              ->select('category', DB::raw('count(*) as count'))
              ->groupBy('category')
              ->pluck('count', 'category'),
            'total_storage_used' => Document::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->sum('file_size'),
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }
}
