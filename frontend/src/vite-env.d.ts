/// <reference types="vite/client" />

interface ImportMetaEnv {
  /**
   * Base URL of the backend Laravel API, including the `/api` prefix.
   * Example: http://localhost:8000/api
   * Defaulted in .env, overridable per environment via .env.local /
   * .env.[mode] or host build-time env vars — never hard-coded in source.
   */
  readonly VITE_API_BASE_URL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
