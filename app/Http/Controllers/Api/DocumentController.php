<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Project;
use App\Models\UserCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_id' => 'required|exists:projects,id',
                'type' => 'nullable|in:plan,blueprint,specification,contract,permit,report,other',
                'category' => 'nullable|string|max:100',
                'version' => 'nullable|string|max:50',
                'is_latest' => 'nullable|boolean',
                'uploaded_by' => 'nullable|exists:users,id',
                'page' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
            ], [
                'project_id.required' => 'Project ID is required',
                'project_id.exists' => 'Invalid project selected',
                'type.in' => 'Invalid document type',
                'category.max' => 'Category cannot exceed 100 characters',
                'version.max' => 'Version cannot exceed 50 characters',
                'is_latest.boolean' => 'Is latest must be a boolean',
                'uploaded_by.exists' => 'Invalid uploader selected',
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

            $query = Document::with(['project', 'uploader'])
                ->where('project_id', $request->project_id)
                ->where('is_deleted', 0);

            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            if ($request->has('category')) {
                $query->where('category', $request->category);
            }

            if ($request->has('version')) {
                $query->where('version', $request->version);
            }

            if ($request->has('is_latest')) {
                $query->where('is_latest', $request->is_latest);
            }

            if ($request->has('uploaded_by')) {
                $query->where('uploaded_by', $request->uploaded_by);
            }

            $limit = $request->get('limit', 10);
            $documents = $query->orderBy('created_at', 'desc')
                ->paginate($limit);

            return $this->toJsonEnc($documents, 'Documents retrieved successfully', '200');

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
                'description' => 'nullable|string|max:1000',
                'type' => 'required|in:plan,blueprint,specification,contract,permit,report,other',
                'category' => 'required|string|max:100',
                'version' => 'required|string|max:50',
                'file' => 'required|file|max:10240', // 10MB max
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:50',
                'access_level' => 'nullable|in:public,private,restricted',
                'expiry_date' => 'nullable|date',
            ], [
                'project_id.required' => 'Project ID is required',
                'project_id.exists' => 'Invalid project selected',
                'title.required' => 'Document title is required',
                'title.max' => 'Title cannot exceed 255 characters',
                'description.max' => 'Description cannot exceed 1000 characters',
                'type.required' => 'Document type is required',
                'type.in' => 'Invalid document type',
                'category.required' => 'Category is required',
                'category.max' => 'Category cannot exceed 100 characters',
                'version.required' => 'Version is required',
                'version.max' => 'Version cannot exceed 50 characters',
                'file.required' => 'Document file is required',
                'file.file' => 'Must be a valid file',
                'file.max' => 'File size cannot exceed 10MB',
                'tags.array' => 'Tags must be an array',
                'tags.*.string' => 'Each tag must be a string',
                'tags.*.max' => 'Each tag cannot exceed 50 characters',
                'access_level.in' => 'Invalid access level',
                'expiry_date.date' => 'Invalid expiry date format',
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

            // Handle file upload
            $file = $request->file('file');
            $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('documents', $fileName, 'public');

            // Check if this is the latest version for this document title
            $isLatest = Document::where('project_id', $request->project_id)
                ->where('title', $request->title)
                ->where('is_latest', true)
                ->doesntExist();

            if (!$isLatest) {
                // Mark previous versions as not latest
                Document::where('project_id', $request->project_id)
                    ->where('title', $request->title)
                    ->update(['is_latest' => false]);
            }

            // Create document record
            $document = new Document();
            $document->project_id = $request->project_id;
            $document->title = $request->title;
            $document->description = $request->description;
            $document->type = $request->type;
            $document->category = $request->category;
            $document->version = $request->version;
            $document->file_path = $filePath;
            $document->file_name = $file->getClientOriginalName();
            $document->file_size = $file->getSize();
            $document->file_type = $file->getClientOriginalExtension();
            $document->uploaded_by = $request->user_id;
            $document->tags = $request->tags ?? [];
            $document->access_level = $request->access_level ?? 'private';
            $document->expiry_date = $request->expiry_date;
            $document->is_latest = true;
            $document->is_deleted = false;
            $document->code = $this->generateDocumentCode();

            $document->save();

            return $this->toJsonEnc($document->load(['project', 'uploader']), 'Document uploaded successfully', '201');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $document = Document::with(['project', 'uploader'])
                ->where('id', $id)
                ->where('is_deleted', 0)
                ->first();

            if (!$document) {
                return $this->toJsonEnc([], 'Document not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $document->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            return $this->toJsonEnc($document, 'Document retrieved successfully', '200');

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
                'type' => 'sometimes|required|in:plan,blueprint,specification,contract,permit,report,other',
                'category' => 'sometimes|required|string|max:100',
                'version' => 'sometimes|required|string|max:50',
                'file' => 'nullable|file|max:10240',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:50',
                'access_level' => 'nullable|in:public,private,restricted',
                'expiry_date' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $document = Document::find($id);

            if (!$document) {
                return $this->toJsonEnc([], 'Document not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $document->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            $updateData = $request->only([
                'title', 'description', 'type', 'category', 'version',
                'tags', 'access_level', 'expiry_date'
            ]);

            if ($request->has('tags')) {
                $updateData['tags'] = $request->tags;
            }

            // Handle new file upload if provided
            if ($request->hasFile('file')) {
                // Delete old file
                if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
                    Storage::disk('public')->delete($document->file_path);
                }

                $file = $request->file('file');
                $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $filePath = $file->storeAs('documents', $fileName, 'public');

                $updateData['file_path'] = $filePath;
                $updateData['file_name'] = $file->getClientOriginalName();
                $updateData['file_size'] = $file->getSize();
                $updateData['file_type'] = $file->getClientOriginalExtension();
            }

            $document->update($updateData);

            return $this->toJsonEnc($document->load(['project', 'uploader']), 'Document updated successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $document = Document::find($id);

            if (!$document) {
                return $this->toJsonEnc([], 'Document not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $document->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            // Soft delete
            $document->update(['is_deleted' => true]);

            return $this->toJsonEnc([], 'Document deleted successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function download(Request $request, $id)
    {
        try {
            $document = Document::with(['project'])
                ->where('id', $id)
                ->where('is_deleted', 0)
                ->first();

            if (!$document) {
                return $this->toJsonEnc([], 'Document not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $document->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            if (!Storage::disk('public')->exists($document->file_path)) {
                return $this->toJsonEnc([], 'File not found', '404');
            }

            $fileUrl = Storage::disk('public')->url($document->file_path);

            return $this->toJsonEnc([
                'download_url' => $fileUrl,
                'file_name' => $document->file_name,
                'file_size' => $document->file_size,
                'file_type' => $document->file_type,
            ], 'Document download URL generated', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function versions(Request $request, $projectId, $title)
    {
        try {
            $project = Project::find($projectId);

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

            $versions = Document::with(['uploader'])
                ->where('project_id', $projectId)
                ->where('title', $title)
                ->where('is_deleted', 0)
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->toJsonEnc($versions, 'Document versions retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    private function generateDocumentCode()
    {
        $code = 'DOC' . strtoupper(Str::random(6));
        
        while (Document::where('code', $code)->exists()) {
            $code = 'DOC' . strtoupper(Str::random(6));
        }

        return $code;
    }
}
