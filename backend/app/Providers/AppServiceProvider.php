<?php

namespace App\Providers;

use App\Models\Resume;
use App\Models\TailoredDocument;
use App\Models\User;
use App\Observers\ResumeObserver;
use App\Observers\TailoredDocumentObserver;
use App\Observers\UserObserver;
use App\Services\JobSources\JobDedupeHasher;
use App\Services\JobSources\JobSourceRegistry;
use App\Services\ModelRouterService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
  /**
   * Register any application services.
   */
  public function register(): void
  {
    // Stateless and config-driven, so one shared instance per request/worker
    // is enough — and tests can swap it wholesale (Requirement 4.1).
    $this->app->singleton(ModelRouterService::class);

    // The job-source seam (Requirement 3.2): the registry reads
    // `config/job_sources.php` and resolves providers from the container, so
    // adding a source never touches the discovery pipeline. Providers
    // themselves are resolved lazily and are not registered here.
    $this->app->singleton(JobSourceRegistry::class);

    // Stateless normalizer behind the Requirement 3.3 dedupe hash. Shared so
    // incoming jobs and stored rows are hashed by one instance of one rule set.
    $this->app->singleton(JobDedupeHasher::class);
  }

  /**
   * Bootstrap any application services.
   */
  public function boot(): void
  {
    // Storage cleanup on record deletion (Requirement 8.4). Registered here
    // rather than with `#[ObservedBy]` attributes on the models so that the
    // full set of records whose deletion touches S3 is visible in one place —
    // the blast radius of this wiring is the reason it should be readable as a
    // list. Each observer explains its own timing and coverage gaps.
    //
    // `Application` is deliberately absent: an application owns no S3 object of
    // its own (the PDF belongs to the `tailored_documents` row, which survives
    // its application via `nullOnDelete`), so an observer for it would either
    // do nothing or delete a live document's file. See
    // S3StorageService::deleteForOwner().
    Resume::observe(ResumeObserver::class);
    TailoredDocument::observe(TailoredDocumentObserver::class);
    User::observe(UserObserver::class);
  }
}
