/**
 * Pipeline health API types and fetcher (Requirement 11.2).
 *
 * Mirrors `backend/app/Http/Controllers/Api/PipelineHealthController`:
 * - `GET /pipeline-health` → always 200, always the full shape. A healthy
 *   account comes back as zeros rather than an empty object, so the widget
 *   never has to distinguish "none" from "unknown".
 *
 * The two counts in the payload are not the same kind of thing:
 * - `stages` is the signed-in user's own work (their listings sitting in
 *   `failed` / `needs_review`).
 * - `queue.failed_jobs` is the framework's dead-letter table: platform-wide
 *   and not attributable to a user, which is why `queue.scope` is sent and
 *   why the UI labels that number as operational.
 */

import { api } from './client'

export interface PipelineHealth {
  stages: {
    failed: number
    needs_review: number
    /** `failed + needs_review`, computed server-side. */
    total: number
  }
  queue: {
    /** Dead-lettered queue jobs, platform-wide. */
    failed_jobs: number
    newest_failed_at: string | null
    scope: 'platform'
  }
  latest_failure: {
    application_id: number
    stage: string | null
    job_title: string | null
    company: string | null
    occurred_at: string | null
    reason: string | null
  } | null
}

export const pipelineHealthKeys = {
  detail: ['pipeline-health'] as const,
}

export function fetchPipelineHealth(): Promise<PipelineHealth> {
  return api.get<PipelineHealth>('/pipeline-health')
}

/** Is there anything here worth showing the user? */
export function hasPipelineTrouble(health: PipelineHealth | undefined): boolean {
  if (!health) return false
  return health.stages.total > 0 || health.queue.failed_jobs > 0
}
