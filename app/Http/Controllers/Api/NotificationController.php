<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserCompany;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Exception;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
                'type' => 'nullable|string|max:50',
                'is_read' => 'nullable|boolean',
                'project_id' => 'nullable|exists:projects,id',
            ], [
                'page.integer' => 'Page must be an integer',
                'page.min' => 'Page must be at least 1',
                'limit.integer' => 'Limit must be an integer',
                'limit.min' => 'Limit must be at least 1',
                'limit.max' => 'Limit cannot exceed 100',
                'type.max' => 'Type cannot exceed 50 characters',
                'is_read.boolean' => 'Is read must be a boolean',
                'project_id.exists' => 'Invalid project selected',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $query = Notification::with(['user', 'project'])
                ->where('user_id', $request->user_id)
                ->where('is_deleted', 0);

            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            if ($request->has('is_read')) {
                $query->where('is_read', $request->is_read);
            }

            if ($request->has('project_id')) {
                $query->where('project_id', $request->project_id);
            }

            $limit = $request->get('limit', 20);
            $notifications = $query->orderBy('created_at', 'desc')
                ->paginate($limit);

            return $this->toJsonEnc($notifications, 'Notifications retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $notification = Notification::with(['user', 'project'])
                ->where('id', $id)
                ->where('user_id', $request->user_id)
                ->where('is_deleted', 0)
                ->first();

            if (!$notification) {
                return $this->toJsonEnc([], 'Notification not found', '404');
            }

            return $this->toJsonEnc($notification, 'Notification retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function markAsRead(Request $request, $id)
    {
        try {
            $notification = Notification::where('id', $id)
                ->where('user_id', $request->user_id)
                ->where('is_deleted', 0)
                ->first();

            if (!$notification) {
                return $this->toJsonEnc([], 'Notification not found', '404');
            }

            $notification->update(['is_read' => true]);

            return $this->toJsonEnc($notification, 'Notification marked as read', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function markAllAsRead(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_id' => 'nullable|exists:projects,id',
                'type' => 'nullable|string|max:50',
            ], [
                'project_id.exists' => 'Invalid project selected',
                'type.max' => 'Type cannot exceed 50 characters',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $query = Notification::where('user_id', $request->user_id)
                ->where('is_deleted', 0)
                ->where('is_read', false);

            if ($request->has('project_id')) {
                $query->where('project_id', $request->project_id);
            }

            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            $updatedCount = $query->update(['is_read' => true]);

            return $this->toJsonEnc(['updated_count' => $updatedCount], 'All notifications marked as read', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function unreadCount(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_id' => 'nullable|exists:projects,id',
                'type' => 'nullable|string|max:50',
            ], [
                'project_id.exists' => 'Invalid project selected',
                'type.max' => 'Type cannot exceed 50 characters',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $query = Notification::where('user_id', $request->user_id)
                ->where('is_deleted', 0)
                ->where('is_read', false);

            if ($request->has('project_id')) {
                $query->where('project_id', $request->project_id);
            }

            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            $count = $query->count();

            return $this->toJsonEnc(['unread_count' => $count], 'Unread notification count retrieved', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $notification = Notification::where('id', $id)
                ->where('user_id', $request->user_id)
                ->first();

            if (!$notification) {
                return $this->toJsonEnc([], 'Notification not found', '404');
            }

            // Soft delete
            $notification->update(['is_deleted' => true]);

            return $this->toJsonEnc([], 'Notification deleted successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function bulkDelete(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'notification_ids' => 'required|array',
                'notification_ids.*' => 'exists:notifications,id',
            ], [
                'notification_ids.required' => 'Notification IDs are required',
                'notification_ids.array' => 'Notification IDs must be an array',
                'notification_ids.*.exists' => 'One or more notifications not found',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

            $deletedCount = Notification::where('user_id', $request->user_id)
                ->whereIn('id', $request->notification_ids)
                ->update(['is_deleted' => true]);

            return $this->toJsonEnc(['deleted_count' => $deletedCount], 'Notifications deleted successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function projectNotifications(Request $request, $projectId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
                'type' => 'nullable|string|max:50',
                'is_read' => 'nullable|boolean',
            ], [
                'page.integer' => 'Page must be an integer',
                'page.min' => 'Page must be at least 1',
                'limit.integer' => 'Limit must be an integer',
                'limit.min' => 'Limit must be at least 1',
                'limit.max' => 'Limit cannot exceed 100',
                'type.max' => 'Type cannot exceed 50 characters',
                'is_read.boolean' => 'Is read must be a boolean',
            ]);

            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }

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

            $query = Notification::with(['user', 'project'])
                ->where('project_id', $projectId)
                ->where('user_id', $request->user_id)
                ->where('is_deleted', 0);

            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            if ($request->has('is_read')) {
                $query->where('is_read', $request->is_read);
            }

            $limit = $request->get('limit', 20);
            $notifications = $query->orderBy('created_at', 'desc')
                ->paginate($limit);

            return $this->toJsonEnc($notifications, 'Project notifications retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }

    public function types(Request $request)
    {
        try {
            $types = Notification::where('user_id', $request->user_id)
                ->where('is_deleted', 0)
                ->distinct()
                ->pluck('type');

            return $this->toJsonEnc($types, 'Notification types retrieved successfully', '200');

        } catch (Exception $e) {
            return $this->toJsonEnc([], $e->getMessage(), '500');
        }
    }
}
