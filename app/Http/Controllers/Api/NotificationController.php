<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->when($request->type, function ($query, $type) {
                return $query->where('type', $type);
            })
            ->when($request->category, function ($query, $category) {
                return $query->where('category', $category);
            })
            ->when($request->priority, function ($query, $priority) {
                return $query->where('priority', $priority);
            })
            ->when($request->status === 'unread', function ($query) {
                return $query->unread();
            })
            ->when($request->status === 'read', function ($query) {
                return $query->read();
            })
            ->when($request->days, function ($query, $days) {
                return $query->recent($days);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'unread_count' => Notification::where('user_id', $request->user()->id)->unread()->count(),
        ]);
    }

    public function markAsRead(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'data' => $notification
        ]);
    }

    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read'
        ]);
    }

    public function markAsUnread(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $notification->markAsUnread();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as unread',
            'data' => $notification
        ]);
    }

    public function destroy(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted successfully'
        ]);
    }

    public function destroyAll(Request $request)
    {
        Notification::where('user_id', $request->user()->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'All notifications deleted successfully'
        ]);
    }

    public function summary(Request $request)
    {
        $user = $request->user();

        $summary = [
            'total_notifications' => Notification::where('user_id', $user->id)->count(),
            'unread_notifications' => Notification::where('user_id', $user->id)->unread()->count(),
            'read_notifications' => Notification::where('user_id', $user->id)->read()->count(),
            'notifications_by_category' => Notification::where('user_id', $user->id)
                ->select('category', DB::raw('count(*) as count'))
                ->groupBy('category')
                ->pluck('count', 'category'),
            'notifications_by_priority' => Notification::where('user_id', $user->id)
                ->select('priority', DB::raw('count(*) as count'))
                ->groupBy('priority')
                ->pluck('count', 'priority'),
            'recent_notifications' => Notification::where('user_id', $user->id)
                ->recent(7)
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }

    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'priority' => 'required|in:low,medium,high,critical',
            'category' => 'required|in:task,project,inspection,snag,document,user,system',
            'action_url' => 'nullable|string|max:500',
            'data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $notification = Notification::create([
            'user_id' => $request->user_id,
            'type' => $request->type,
            'title' => $request->title,
            'message' => $request->message,
            'priority' => $request->priority,
            'category' => $request->category,
            'action_url' => $request->action_url,
            'data' => $request->data ?? [],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notification created successfully',
            'data' => $notification
        ], 201);
    }
}
