<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\EnsureTokenIsValid;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\authenticate\AuthLogin;
use App\Http\Controllers\Auth\LoginRegistrationController;
use App\Http\Controllers\Dashboard\DashboardController;

// Pages Controller
use App\Http\Controllers\ResumeController;
use App\Http\Controllers\JobsController;
use App\Http\Controllers\ResumeUploadController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\JobListingController;

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

Route::middleware([EnsureTokenIsValid::class])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard-blank');
    Route::get('/dashboard', [DashboardController::class, 'crm'])->name('dashboard-crm');

    Route::post('/resumes', [ResumeController::class, 'store'])->name('resumes.store');
    Route::resource('resumes', ResumeController::class);

    // Route::get('/jobs', [JobsController::class, 'fetchJobs'])->name('jobs.index');
    // Route::resource('jobs', JobsController::class);

    Route::resource('/ResumeUpload', ResumeUploadController::class);

    Route::resource('/UserProfile', UserProfileController::class);

    Route::get('/update-job-descriptions', [JobListingController::class, 'updateJobDescriptions'])->name('update.job.descriptions');

    Route::get('/Jobs', [JobListingController::class, 'fetchJobs'])->name('Jobs.index');
    Route::get('Jobs/{Job}', [JobListingController::class, 'show'])->name('Jobs.show');
    Route::resource('/Jobs', JobListingController::class);


    Route::get('/view/profile', [DashboardController::class, 'view'])->name('view-profile');
    Route::get('/add/post', [DashboardController::class, 'add'])->name('add-post');
    
});
