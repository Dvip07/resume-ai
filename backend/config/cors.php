<?php

return [

  /*
  |--------------------------------------------------------------------------
  | Cross-Origin Resource Sharing (CORS) Configuration
  |--------------------------------------------------------------------------
  |
  | Here you may configure your settings for cross-origin resource sharing
  | or "CORS". This determines what cross-origin operations may execute
  | in web browsers. You are free to adjust these settings as needed.
  |
  | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
  |
  */

  'paths' => ['api/*', 'sanctum/csrf-cookie'],

  'allowed_methods' => ['*'],

  /*
  |--------------------------------------------------------------------------
  | Allowed Origins
  |--------------------------------------------------------------------------
  |
  | Explicit allow-list of frontend origins permitted to call the `api/*`
  | routes. No wildcard ("*") is used since requests carry an
  | `Authorization: Bearer <sanctum token>` header (Requirement 1.9). The
  | frontend project doesn't exist yet, so this anticipates its local Vite
  | dev server URL plus an env-driven production origin to be confirmed
  | once the frontend is deployed.
  |
  */

  'allowed_origins' => array_values(array_unique(array_filter([
    'http://localhost:5173',
    env('FRONTEND_URL', 'http://localhost:5173'),
  ]))),

  'allowed_origins_patterns' => [],

  'allowed_headers' => ['*'],

  'exposed_headers' => [],

  'max_age' => 0,

  /*
  |--------------------------------------------------------------------------
  | Credentials
  |--------------------------------------------------------------------------
  |
  | Enabled because the frontend authenticates with the backend-set httpOnly
  | auth cookie (open decision #6) and therefore sends requests with
  | `credentials: 'include'`. This is only safe alongside the explicit
  | allowed-origins list above — a wildcard origin is invalid with credentials
  | and is forbidden by Requirement 1.9 regardless.
  |
  */

  'supports_credentials' => true,

];
