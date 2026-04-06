<?php

use App\Http\Controllers\NewsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WordPressPostingController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

// WordPress synchronization
Route::middleware('auth:sanctum')->group(function () {
	Route::get('/auth/me', [AuthController::class, 'me']);
	Route::post('/auth/logout', [AuthController::class, 'logout']);

	// WordPress synchronization
	Route::post('/sync-wordpress', [NewsController::class, 'syncWordPressData']);

	// Email processing
	Route::post('/process-emails', [NewsController::class, 'processEmails']);

	// News management
	Route::get('/news', [NewsController::class, 'getNewsList']);
	Route::get('/news/pending', [NewsController::class, 'getPendingNews']);
	Route::get('/news/{id}', [NewsController::class, 'getNewsById']);
	Route::patch('/news/{id}/status/{status}', [NewsController::class, 'updateNewsStatus']);
	Route::patch('/news/bulk/status/{status}', [NewsController::class, 'bulkUpdateNewsStatus']);

	// WordPress posting
	Route::post('/news/{id}/post-to-wordpress', [WordPressPostingController::class, 'postNews']);
	Route::post('/news/bulk-post-to-wordpress', [WordPressPostingController::class, 'bulkPostNews']);
	Route::post('/publish-pending', [WordPressPostingController::class, 'publishPendingNews']);
	Route::get('/news/{id}/preview', [WordPressPostingController::class, 'previewNews']);

	// Statistics
	Route::get('/stats', [WordPressPostingController::class, 'newsStats']);
});
