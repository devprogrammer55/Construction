<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\PhotoAlbum;
use App\Models\Project;
use App\Models\UserCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class PhotoController extends Controller
{
    public function index(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_id' => 'required|exists:projects,id',
                'album_id' => 'nullable|exists:photo_albums,id',
                'tags' => 'nullable|string',
                'uploaded_by' => 'nullable|exists:users,id',
                'page' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
            ], [
                'project_id.required' => 'Project ID is required',
                'project_id.exists' => 'Invalid project selected',
                'album_id.exists' => 'Invalid album selected',
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

            $query = Photo::with(['project', 'album', 'uploader'])
                ->where('project_id', $request->project_id)
                ->where('is_deleted', 0);

            if ($request->has('album_id')) {
                $query->where('album_id', $request->album_id);
            }

            if ($request->has('tags')) {
                $tags = explode(',', $request->tags);
                $query->where(function ($q) use ($tags) {
                    foreach ($tags as $tag) {
                        $q->orWhereJsonContains('tags', trim($tag));
                    }
                });
            }

            if ($request->has('uploaded_by')) {
                $query->where('uploaded_by', $request->uploaded_by);
            }

            $limit = $request->get('limit', 10);
            $photos = $query->orderBy('created_at', 'desc')
                ->paginate($limit);

            return $this->toJsonEnc($photos, 'Photos retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_id' => 'required|exists:projects,id',
                'album_id' => 'nullable|exists:photo_albums,id',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'photo' => 'required|image|mimes:jpeg,png,jpg|max:5120', // 5MB max
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:50',
                'location' => 'nullable|string|max:500',
                'taken_at' => 'nullable|date',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
            ], [
                'project_id.required' => 'Project ID is required',
                'project_id.exists' => 'Invalid project selected',
                'album_id.exists' => 'Invalid album selected',
                'title.required' => 'Photo title is required',
                'title.max' => 'Title cannot exceed 255 characters',
                'description.max' => 'Description cannot exceed 1000 characters',
                'photo.required' => 'Photo file is required',
                'photo.image' => 'Must be an image file',
                'photo.mimes' => 'Photo must be in JPEG, PNG, or JPG format',
                'photo.max' => 'Photo file size cannot exceed 5MB',
                'tags.array' => 'Tags must be an array',
                'tags.*.string' => 'Each tag must be a string',
                'tags.*.max' => 'Each tag cannot exceed 50 characters',
                'location.max' => 'Location cannot exceed 500 characters',
                'taken_at.date' => 'Invalid taken date format',
                'latitude.numeric' => 'Latitude must be a number',
                'longitude.numeric' => 'Longitude must be a number',
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

            // Handle photo upload
            $photo = $request->file('photo');
            $photoName = Str::uuid() . '.' . $photo->getClientOriginalExtension();
            $photoPath = $photo->storeAs('photos', $photoName, 'public');

            // Create photo record
            $photoRecord = new Photo();
            $photoRecord->project_id = $request->project_id;
            $photoRecord->album_id = $request->album_id;
            $photoRecord->title = $request->title;
            $photoRecord->description = $request->description;
            $photoRecord->file_path = $photoPath;
            $photoRecord->file_name = $photo->getClientOriginalName();
            $photoRecord->file_size = $photo->getSize();
            $photoRecord->file_type = $photo->getClientOriginalExtension();
            $photoRecord->uploaded_by = $request->user_id;
            $photoRecord->tags = $request->tags ?? [];
            $photoRecord->location = $request->location;
            $photoRecord->taken_at = $request->taken_at;
            $photoRecord->latitude = $request->latitude;
            $photoRecord->longitude = $request->longitude;
            $photoRecord->is_deleted = false;
            $photoRecord->code = $this->generatePhotoCode();

            $photoRecord->save();

            return $this->toJsonEnc($photoRecord->load(['project', 'album', 'uploader']), 'Photo uploaded successfully', '201');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $photo = Photo::with(['project', 'album', 'uploader'])
                ->where('id', $id)
                ->where('is_deleted', 0)
                ->first();

            if (!$photo) {
                return $this->toJsonEnc([], 'Photo not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $photo->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            return $this->toJsonEnc($photo, 'Photo retrieved successfully', '200');

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
                'album_id' => 'nullable|exists:photo_albums,id',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:50',
                'location' => 'nullable|string|max:500',
                'taken_at' => 'nullable|date',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'photo' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $photo = Photo::find($id);

            if (!$photo) {
                return $this->toJsonEnc([], 'Photo not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $photo->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            $updateData = $request->only([
                'title', 'description', 'album_id', 'location', 'taken_at',
                'latitude', 'longitude'
            ]);

            if ($request->has('tags')) {
                $updateData['tags'] = $request->tags;
            }

            // Handle new photo upload if provided
            if ($request->hasFile('photo')) {
                // Delete old photo
                if ($photo->file_path && Storage::disk('public')->exists($photo->file_path)) {
                    Storage::disk('public')->delete($photo->file_path);
                }

                $newPhoto = $request->file('photo');
                $photoName = Str::uuid() . '.' . $newPhoto->getClientOriginalExtension();
                $photoPath = $newPhoto->storeAs('photos', $photoName, 'public');

                $updateData['file_path'] = $photoPath;
                $updateData['file_name'] = $newPhoto->getClientOriginalName();
                $updateData['file_size'] = $newPhoto->getSize();
                $updateData['file_type'] = $newPhoto->getClientOriginalExtension();
            }

            $photo->update($updateData);

            return $this->toJsonEnc($photo->load(['project', 'album', 'uploader']), 'Photo updated successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $photo = Photo::find($id);

            if (!$photo) {
                return $this->toJsonEnc([], 'Photo not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $photo->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            // Soft delete
            $photo->update(['is_deleted' => true]);

            return $this->toJsonEnc([], 'Photo deleted successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function download(Request $request, $id)
    {
        try {
            $photo = Photo::with(['project'])
                ->where('id', $id)
                ->where('is_deleted', 0)
                ->first();

            if (!$photo) {
                return $this->toJsonEnc([], 'Photo not found', '404');
            }

            // Check if user has access to this company
            $hasAccess = UserCompany::where('user_id', $request->user_id)
                ->where('company_id', $photo->project->company_id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return $this->toJsonEnc([], 'Access denied', '403');
            }

            if (!Storage::disk('public')->exists($photo->file_path)) {
                return $this->toJsonEnc([], 'File not found', '404');
            }

            $fileUrl = Storage::disk('public')->url($photo->file_path);

            return $this->toJsonEnc([
                'download_url' => $fileUrl,
                'file_name' => $photo->file_name,
                'file_size' => $photo->file_size,
                'file_type' => $photo->file_type,
            ], 'Photo download URL generated', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function albums(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_id' => 'required|exists:projects,id',
                'page' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
            ], [
                'project_id.required' => 'Project ID is required',
                'project_id.exists' => 'Invalid project selected',
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

            $limit = $request->get('limit', 10);
            $albums = PhotoAlbum::with(['project', 'photos'])
                ->where('project_id', $request->project_id)
                ->where('is_deleted', 0)
                ->orderBy('created_at', 'desc')
                ->paginate($limit);

            return $this->toJsonEnc($albums, 'Photo albums retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function createAlbum(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_id' => 'required|exists:projects,id',
                'name' => 'required|string|max:100',
                'description' => 'nullable|string|max:500',
                'cover_photo_id' => 'nullable|exists:photos,id',
            ], [
                'project_id.required' => 'Project ID is required',
                'project_id.exists' => 'Invalid project selected',
                'name.required' => 'Album name is required',
                'name.max' => 'Name cannot exceed 100 characters',
                'description.max' => 'Description cannot exceed 500 characters',
                'cover_photo_id.exists' => 'Invalid cover photo selected',
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

            $album = new PhotoAlbum();
            $album->project_id = $request->project_id;
            $album->name = $request->name;
            $album->description = $request->description;
            $album->cover_photo_id = $request->cover_photo_id;
            $album->created_by = $request->user_id;
            $album->is_deleted = false;

            $album->save();

            return $this->toJsonEnc($album->load(['project', 'photos']), 'Photo album created successfully', '201');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    private function generatePhotoCode()
    {
        $code = 'PHOTO' . strtoupper(Str::random(6));
        
        while (Photo::where('code', $code)->exists()) {
            $code = 'PHOTO' . strtoupper(Str::random(6));
        }

        return $code;
    }
}
