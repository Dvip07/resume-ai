<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationSettingController;
use App\Http\Controllers\Api\JobListingController;
use App\Http\Controllers\Api\PipelineHealthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ResumeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->get('/me', [AuthController::class, 'me']);

// Retained for backward compatibility in case anything already depends on
// this closure-based route; prefer GET /api/me per design.md going forward.
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/resumes', [ResumeController::class, 'index']);
    Route::post('/resumes', [ResumeController::class, 'store']);
    Route::get('/resumes/{resume}', [ResumeController::class, 'show']);
    Route::delete('/resumes/{resume}', [ResumeController::class, 'destroy']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::patch('/profile', [ProfileController::class, 'update']);

    // Automation consent and throttles (Requirements 9.1, 9.3, 9.7). Enabling
    // the LinkedIn opt-in requires an explicit risk acknowledgment in the same
    // request; see AutomationSettingController.
    Route::get('/automation-settings', [AutomationSettingController::class, 'show']);
    Route::patch('/automation-settings', [AutomationSettingController::class, 'update']);

    // Failure counts for the dashboard status widget (Requirement 11.2). The
    // per-stage numbers are the user's own; the `failed_jobs` count is
    // queue-level and global — see PipelineHealthController.
    Route::get('/pipeline-health', [PipelineHealthController::class, 'show']);

    Route::get('/jobs', [JobListingController::class, 'index']);
    // Declared before the wildcard so "discover" is never read as an id.
    Route::post('/jobs/discover', [JobListingController::class, 'discover']);
    Route::get('/jobs/{jobListing}', [JobListingController::class, 'show']);
    // Manual pipeline triggers for one listing. Each queues its stage's job
    // and answers 202 — the work talks to models and third parties, so no
    // request waits on it (Requirement 11.1).
    Route::post('/jobs/{jobListing}/rescore', [JobListingController::class, 'rescore']);
    Route::post('/jobs/{jobListing}/tailor', [JobListingController::class, 'tailor']);
    Route::post('/jobs/{jobListing}/apply', [JobListingController::class, 'apply']);

    Route::get('/applications', [ApplicationController::class, 'index']);
    Route::get('/applications/{application}', [ApplicationController::class, 'show']);
    // Manual status updates only (Requirement 10.4); the pipeline's own
    // `status` is not settable here.
    Route::patch('/applications/{application}', [ApplicationController::class, 'update']);
});
