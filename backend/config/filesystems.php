<?php

return [

  /*
  |--------------------------------------------------------------------------
  | Default Filesystem Disk
  |--------------------------------------------------------------------------
  |
  | Here you may specify the default filesystem disk that should be used
  | by the framework. The "local" disk, as well as a variety of cloud
  | based disks are available to your application. Just store away!
  |
  */

  'default' => env('FILESYSTEM_DISK', 'local'),

  /*
  |--------------------------------------------------------------------------
  | Resume / Artifact Disk
  |--------------------------------------------------------------------------
  |
  | Disk used for durable user artifacts (original resume uploads, tailored
  | resumes, cover letters) — see Requirement 8.1. Defaults to 's3'; local
  | development without AWS credentials can set RESUME_STORAGE_DISK=public
  | in .env to keep the whole upload flow working off the local disk.
  |
  */

  'resume_disk' => env('RESUME_STORAGE_DISK', 's3'),

  /*
  |--------------------------------------------------------------------------
  | Signed URL Lifetime
  |--------------------------------------------------------------------------
  |
  | Minutes a temporary signed URL handed to the frontend stays valid — see
  | Requirement 8.3, which requires artifacts to be served through short-lived
  | signed URLs rather than public permanent ones. Read by
  | App\Services\Storage\S3StorageService; the design's 15 minutes is the
  | default and lives here instead of as a literal in the service.
  |
  | The number trades two risks against each other. Too long and a URL pasted
  | into a chat or left in a browser history keeps working for anyone who finds
  | it; too short and a user who opens a document, reads it, and hits refresh
  | gets a denial. Fifteen minutes covers "click the link, read the PDF,
  | re-download it" without the link outliving the session that produced it.
  | Clamped to at least 1 in the service, since a zero or negative TTL would
  | mint URLs that are already expired.
  |
  */

  'signed_url_ttl_minutes' => (int) env('STORAGE_SIGNED_URL_TTL_MINUTES', 15),

  /*
  |--------------------------------------------------------------------------
  | Upload Retry (in-process)
  |--------------------------------------------------------------------------
  |
  | Bounded retry-with-backoff applied around the individual object write, read
  | by App\Services\Storage\StorageWriteRetrier — see Requirement 8.5.
  |
  | This sits *below* the queued-job retry, it does not replace it. A queue
  | retry of TailorResume/TailorCoverLetter re-runs the whole job, which means
  | re-paying for the model calls and the LaTeX compile because a bucket had a
  | bad second; that is the right response to a dead worker and an expensive
  | one to a single failed PUT. So the write is retried here first, and only a
  | write that keeps failing is allowed to surface and let the queue take over
  | with its own (much longer) backoff.
  |
  | 'attempts' is total attempts, not retries: 1 disables in-process retry
  | entirely and restores the pre-13.2 behaviour. 'backoff_ms' is the delay
  | before the second attempt and doubles for each attempt after it, so the
  | default 3/200 spends at most ~600ms in sleeps before giving up — short
  | enough that a queue worker is not held hostage by a bucket that is really
  | down, long enough to ride out a throttle or a re-election.
  |
  | 'interactive_attempts' is the cap for the synchronous upload path
  | (App\Http\Controllers\Api\ResumeController::store), where an HTTP client is
  | waiting on the response and there is no queue behind it to try again later.
  | It is deliberately lower: the user can retry the upload themselves in less
  | time than a long server-side retry loop would take, and a request that hangs
  | reads as a broken app.
  |
  */

  'upload_retry' => [
    'attempts' => (int) env('STORAGE_UPLOAD_RETRY_ATTEMPTS', 3),
    'interactive_attempts' => (int) env('STORAGE_UPLOAD_RETRY_INTERACTIVE_ATTEMPTS', 2),
    'backoff_ms' => (int) env('STORAGE_UPLOAD_RETRY_BACKOFF_MS', 200),
  ],

  /*
  |--------------------------------------------------------------------------
  | Filesystem Disks
  |--------------------------------------------------------------------------
  |
  | Here you may configure as many filesystem "disks" as you wish, and you
  | may even configure multiple disks of the same driver. Defaults have
  | been set up for each driver as an example of the required values.
  |
  | Supported Drivers: "local", "ftp", "sftp", "s3"
  |
  */

  'disks' => [

    'local' => [
      'driver' => 'local',
      'root' => storage_path('app'),
      'throw' => false,
    ],

    'public' => [
      'driver' => 'local',
      'root' => storage_path('app/public'),
      'url' => env('APP_URL') . '/storage',
      'visibility' => 'public',
      'throw' => false,
    ],

    's3' => [
      'driver' => 's3',
      'key' => env('AWS_ACCESS_KEY_ID'),
      'secret' => env('AWS_SECRET_ACCESS_KEY'),
      'region' => env('AWS_DEFAULT_REGION'),
      'bucket' => env('AWS_BUCKET'),
      'url' => env('AWS_URL'),
      'endpoint' => env('AWS_ENDPOINT'),
      'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
      'throw' => false,
    ],

  ],

  /*
  |--------------------------------------------------------------------------
  | Symbolic Links
  |--------------------------------------------------------------------------
  |
  | Here you may configure the symbolic links that will be created when the
  | `storage:link` Artisan command is executed. The array keys should be
  | the locations of the links and the values should be their targets.
  |
  */

  'links' => [
    public_path('storage') => storage_path('app/public'),
  ],

];
