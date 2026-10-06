<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\authenticate\AuthLogin;
use App\Http\Controllers\Auth\LoginRegistrationController;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| What is left here is the server-rendered login/register pair and a local
| cache-clearing helper. Every authenticated screen now lives in `frontend/`
| and talks to `routes/api.php` (Requirements 1.4, 12.2):
|
| - the resume flow moved at resume cutover (Api\ResumeController);
| - the dashboard, jobs listing/detail, and UserProfile screens moved with it
|   (Api\PipelineHealthController, Api\JobListingController,
|   Api\ProfileController), and their Blade controllers/views are deleted.
|
| Legacy URLs for migrated screens therefore 404 rather than quietly serving an
| older implementation (Requirement 1.7); `tests/Feature/LegacyBladeRoutesTest`
| pins that. The auth pages stay for now — whether they move to `frontend/` or
| remain server-rendered is decided explicitly in task 16.5
| (Requirement 1.7/12.4).
|
| `GET /update-job-descriptions` was removed with the legacy scrapers (task
| 9.3): enrichment is a queued job, not a URL a browser triggers.
|
*/

date_default_timezone_set('Asia/Kolkata');
Route::get('/refresh', function () {
    Artisan::call('key:generate');
    Artisan::call('cache:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');
    Artisan::call('config:clear');
    Artisan::call('optimize:clear');
    return 'Refresh Done';
});

Route::controller(LoginRegistrationController::class)->group(function () {
    Route::post('/authenticate', 'authenticate')->name('authenticate');
    Route::post('/store', 'store')->name('store');
    Route::post('/logout', 'logout')->name('logout');
});

Route::get('/authenticate/login', [AuthLogin::class, 'login'])->name('authenticate-login');
Route::get('/authenticate/register', [AuthLogin::class, 'register'])->name('authenticate-register');
