<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhotoAlbum;
use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PhotoAlbumController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $albums = PhotoAlbum::with(['project', 'createdBy', 'photos'])
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
            ->when($request->search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->tag, function ($query, $tag) {
                return $query->whereJsonContains('tags', $tag);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $albums
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|exists:projects,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'boolean',
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
            $album = PhotoAlbum::create([
                'project_id' => $request->project_id,
                'name' => $request->name,
                'description' => $request->description,
                'created_by' => $request->user()->id,
                'is_public' => $request->is_public ?? true,
                'tags' => $request->tags ?? [],
                'metadata' => [],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Photo album created successfully',
                'data' => $album->load(['project', 'createdBy'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create photo album',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, PhotoAlbum $album)
    {
        $album->load(['project', 'createdBy', 'photos.uploadedBy']);

        return response()->json([
            'success' => true,
            'data' => $album
        ]);
    }

    public function update(Request $request, PhotoAlbum $album)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'boolean',
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
            $album->update($request->all());
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Photo album updated successfully',
                'data' => $album->load(['project', 'createdBy'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update photo album',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function uploadPhotos(Request $request, PhotoAlbum $album)
    {
        $validator = Validator::make($request->all(), [
            'photos' => 'required|array',
            'photos.*' => 'image|mimes:jpeg,png,jpg|max:2048',
            'titles' => 'nullable|array',
            'titles.*' => 'nullable|string|max:255',
            'descriptions' => 'nullable|array',
            'descriptions.*' => 'nullable|string|max:500',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'location' => 'nullable|string|max:255',
            'taken_at' => 'nullable|date',
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
                $path = $photo->store('photos', 'public');
                
                $photoModel = Photo::create([
                    'album_id' => $album->id,
                    'file_path' => $path,
                    'file_name' => $photo->getClientOriginalName(),
                    'file_size' => $photo->getSize(),
                    'mime_type' => $photo->getMimeType(),
                    'title' => $request->titles[$index] ?? null,
                    'description' => $request->descriptions[$index] ?? null,
                    'tags' => $request->tags ?? [],
                    'location' => $request->location,
                    'taken_at' => $request->taken_at ? 
                        now()->parse($request->taken_at) : now(),
                    'uploaded_by' => $request->user()->id,
                    'metadata' => [],
                ]);

                $uploadedPhotos[] = $photoModel;
            }

            // Update album cover if it's the first photo
            if ($album->photos()->count() === count($uploadedPhotos)) {
                $album->update(['cover_photo' => $uploadedPhotos[0]->file_path]);
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

    public function getPhotos(Request $request, PhotoAlbum $album)
    {
        $photos = $album->photos()
            ->with('uploadedBy')
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $photos
        ]);
    }

    public function setCoverPhoto(Request $request, PhotoAlbum $album)
    {
        $validator = Validator::make($request->all(), [
            'photo_id' => 'required|exists:photos,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $photo = $album->photos()->find($request->photo_id);
        if (!$photo) {
            return response()->json([
                'success' => false,
                'message' => 'Photo not found in this album'
            ], 404);
        }

        $album->update(['cover_photo' => $photo->file_path]);

        return response()->json([
            'success' => true,
            'message' => 'Cover photo updated successfully',
            'data' => $album
        ]);
    }

    public function destroy(Request $request, PhotoAlbum $album)
    {
        DB::beginTransaction();
        try {
            // Delete all photos in the album
            foreach ($album->photos as $photo) {
                if ($photo->file_path) {
                    Storage::disk('public')->delete($photo->file_path);
                }
                $photo->delete();
            }

            $album->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Photo album deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete photo album',
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
            'total_albums' => PhotoAlbum::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->count(),
            'total_photos' => Photo::whereHas('album.project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->count(),
            'total_storage_used' => Photo::whereHas('album.project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->sum('file_size'),
            'albums_by_project' => PhotoAlbum::whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->select('project_id', DB::raw('count(*) as count'))
              ->groupBy('project_id')
              ->with('project:id,name')
              ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }
}
