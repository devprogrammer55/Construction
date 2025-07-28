<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\InspectionController;
use App\Http\Controllers\Api\SnagReportController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\NotificationController;

/*
|--------------------------------------------------------------------------
| API Routes - Refactored to match CODE_PATTERN_GUIDE.md
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->middleware(['decrypt', 'verifyApiKey'])->group(function () {
    
    // Authentication routes (no token required)
    Route::prefix('auth')->group(function () {
        Route::post('register/step1', [AuthController::class, 'registerStep1']);
        Route::post('register/step2', [AuthController::class, 'registerStep2']);
        Route::post('register/step3', [AuthController::class, 'registerStep3']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    // Protected routes (require token)
    Route::middleware(['checkUserToken'])->group(function () {
        
        // User profile management
        Route::prefix('profile')->group(function () {
            Route::get('/', [AuthController::class, 'profile']);
            Route::put('/', [AuthController::class, 'updateProfile']);
            Route::post('change-password', [AuthController::class, 'changePassword']);
            Route::post('logout', [AuthController::class, 'logout']);
        });

        // Company management
        Route::prefix('companies')->group(function () {
            Route::get('/', [CompanyController::class, 'index']);
            Route::post('/', [CompanyController::class, 'store']);
            Route::get('/{company}', [CompanyController::class, 'show']);
            Route::put('/{company}', [CompanyController::class, 'update']);
            Route::delete('/{company}', [CompanyController::class, 'destroy']);
            Route::post('/{company}/invite', [CompanyController::class, 'inviteUser']);
            Route::post('/{company}/accept-invite', [CompanyController::class, 'acceptInvite']);
            Route::get('/{company}/members', [CompanyController::class, 'getMembers']);
        });

        // Project management
        Route::prefix('projects')->group(function () {
            Route::get('/', [ProjectController::class, 'index']);
            Route::post('/', [ProjectController::class, 'store']);
            Route::get('/{project}', [ProjectController::class, 'show']);
            Route::put('/{project}', [ProjectController::class, 'update']);
            Route::delete('/{project}', [ProjectController::class, 'destroy']);
            Route::put('/{project}/progress', [ProjectController::class, 'updateProgress']);
            Route::get('/{project}/analytics', [ProjectController::class, 'analytics']);
            Route::get('/{project}/timeline', [ProjectController::class, 'timeline']);

            // Project activities
            Route::get('/{project}/activities', [ProjectController::class, 'getActivities']);
            Route::post('/{project}/activities', [ProjectController::class, 'storeActivity']);
            Route::put('/{project}/activities/{activity}', [ProjectController::class, 'updateActivity']);
            Route::delete('/{project}/activities/{activity}', [ProjectController::class, 'deleteActivity']);
        });

        // Task management
        Route::prefix('tasks')->group(function () {
            Route::get('/', [TaskController::class, 'index']);
            Route::post('/', [TaskController::class, 'store']);
            Route::get('/{task}', [TaskController::class, 'show']);
            Route::put('/{task}', [TaskController::class, 'update']);
            Route::delete('/{task}', [TaskController::class, 'destroy']);
            Route::put('/{task}/status', [TaskController::class, 'updateStatus']);
            Route::put('/{task}/progress', [TaskController::class, 'updateProgress']);
            Route::post('/{task}/assign', [TaskController::class, 'assignUser']);
            Route::post('/{task}/photos', [TaskController::class, 'uploadPhotos']);
            Route::get('/{task}/photos', [TaskController::class, 'getPhotos']);
            Route::post('/{task}/comments', [TaskController::class, 'addComment']);
            Route::get('/{task}/comments', [TaskController::class, 'getComments']);
        });

        // Inspection management
        Route::prefix('inspections')->group(function () {
            Route::get('/', [InspectionController::class, 'index']);
            Route::post('/', [InspectionController::class, 'store']);
            Route::get('/{inspection}', [InspectionController::class, 'show']);
            Route::put('/{inspection}', [InspectionController::class, 'update']);
            Route::delete('/{inspection}', [InspectionController::class, 'destroy']);
            Route::put('/{inspection}/conduct', [InspectionController::class, 'conduct']);
            Route::post('/{inspection}/complete', [InspectionController::class, 'complete']);
        });

        // Snag report management
        Route::prefix('snags')->group(function () {
            Route::get('/', [SnagReportController::class, 'index']);
            Route::post('/', [SnagReportController::class, 'store']);
            Route::get('/{snag}', [SnagReportController::class, 'show']);
            Route::put('/{snag}', [SnagReportController::class, 'update']);
            Route::delete('/{snag}', [SnagReportController::class, 'destroy']);
            Route::put('/{snag}/status', [SnagReportController::class, 'updateStatus']);
            Route::post('/{snag}/assign', [SnagReportController::class, 'assignUser']);
            Route::get('/{snag}/analytics', [SnagReportController::class, 'analytics']);
        });

        // Document management
        Route::prefix('documents')->group(function () {
            Route::get('/', [DocumentController::class, 'index']);
            Route::post('/', [DocumentController::class, 'store']);
            Route::get('/{document}', [DocumentController::class, 'show']);
            Route::put('/{document}', [DocumentController::class, 'update']);
            Route::delete('/{document}', [DocumentController::class, 'destroy']);
            Route::get('/{document}/download', [DocumentController::class, 'download']);
            Route::post('/{document}/version', [DocumentController::class, 'createVersion']);
            Route::get('/{document}/versions', [DocumentController::class, 'getVersions']);
        });

        // Photo gallery
        Route::prefix('photos')->group(function () {
            Route::get('/', [PhotoController::class, 'index']);
            Route::post('/', [PhotoController::class, 'store']);
            Route::get('/{photo}', [PhotoController::class, 'show']);
            Route::delete('/{photo}', [PhotoController::class, 'destroy']);
            Route::get('/{photo}/download', [PhotoController::class, 'download']);
            Route::post('/{photo}/like', [PhotoController::class, 'toggleLike']);
            Route::post('/{photo}/comments', [PhotoController::class, 'addComment']);
            Route::get('/{photo}/comments', [PhotoController::class, 'getComments']);
        });

        // Photo albums
        Route::prefix('albums')->group(function () {
            Route::get('/', [PhotoController::class, 'getAlbums']);
            Route::post('/', [PhotoController::class, 'createAlbum']);
            Route::get('/{album}', [PhotoController::class, 'getAlbum']);
            Route::put('/{album}', [PhotoController::class, 'updateAlbum']);
            Route::delete('/{album}', [PhotoController::class, 'deleteAlbum']);
            Route::post('/{album}/photos', [PhotoController::class, 'addPhotosToAlbum']);
        });

        // Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::put('/{notification}/read', [NotificationController::class, 'markAsRead']);
            Route::put('/read-all', [NotificationController::class, 'markAllAsRead']);
            Route::get('/unread-count', [NotificationController::class, 'getUnreadCount']);
            Route::put('/preferences', [NotificationController::class, 'updatePreferences']);
            Route::get('/preferences', [NotificationController::class, 'getPreferences']);
        });

        // Dashboard endpoints
        Route::prefix('dashboard')->group(function () {
            Route::get('/', [ProjectController::class, 'dashboard']);
            Route::get('/analytics', [ProjectController::class, 'globalAnalytics']);
            Route::get('/tasks-summary', [TaskController::class, 'summary']);
            Route::get('/snags-summary', [SnagReportController::class, 'summary']);
        });
    });
});
