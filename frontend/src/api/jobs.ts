/**
 * Job listing API types, query keys, and fetchers (Requirements 10.1, 10.2,
 * 11.1).
 *
 * Mirrors `backend/app/Http/Controllers/Api/JobListingController`:
 * - `GET  /jobs`              → paginator page, filterable by `pipeline_stage`,
 *                               `source`, and minimum `rating`
 * - `GET  /jobs/{id}`         → one listing with the full description
 * - `POST /jobs/discover`     → 202, queues a discovery run
 * - `POST /jobs/{id}/rescore` → 202, queues a fresh score
 * - `POST /jobs/{id}/tailor`  → 202, queues document generation
 * - `POST /jobs/{id}/apply`   → 202, queues submission
 *
 * Every write is 202-and-queued, never a completed result: the pipeline work
 * happens on a queue worker (Requirement 11.1). So each action here invalidates
 * the reads and lets polling surface the outcome, rather than pretending the
 * response says anything about success.
 *
 * Stage vocabulary, labels, star formatting, and `fit_analysis` reading are
 * reused from `./applications` — a listing's stage and a tracked application's
 * stage are the same column, and two copies would drift.
 */

import { api } from './client'
import type { PipelineStage } from './applications'
import type { Paginated } from './resumes'

/* ------------------------------------------------------------------- types */

/** The user's current (highest-attempt) score for a listing, or null. */
export interface JobScore {
  stars: number | null
  recommended_action: string | null
  attempt_number: number | null
  rationale: string | null
  fit_analysis: unknown
  created_at: string | null
}

export interface JobListing {
  id: number
  api_id: string | null
  title: string | null
  company: string | null
  location: string | null
  description: string | null
  api_source: string | null
  source_key: string | null
  application_url: string | null
  posted_at: string | null
  is_active: boolean
  parsed_skills: unknown
  pipeline_stage: PipelineStage | string | null
  salary_min: number | null
  salary_max: number | null
  currency: string | null
  created_at: string | null
  updated_at: string | null
  score: JobScore | null
}

export interface JobFilters {
  pipeline_stage?: string
  /** Matches either `source_key` or the legacy `api_source`. */
  source?: string
  /** Minimum stars on the user's latest score, 1–5. */
  rating?: number
}

export interface DiscoverInput {
  roles?: string[]
  location?: string | null
  limit?: number
}

/** Every queued endpoint answers the same way. */
export interface QueuedResponse {
  message: string
}

/* -------------------------------------------------------------- query keys */

export const jobKeys = {
  all: ['jobs'] as const,
  list: (page: number, filters: JobFilters) =>
    ['jobs', 'list', page, filters] as const,
  detail: (id: number | string) => ['jobs', 'detail', String(id)] as const,
}

/* ---------------------------------------------------------------- fetchers */

export function fetchJobs(
  page: number,
  filters: JobFilters = {},
): Promise<Paginated<JobListing>> {
  return api.get<Paginated<JobListing>>('/jobs', {
    query: {
      page,
      // Empty strings would be sent as a filter the backend then rejects, so
      // unset filters are dropped rather than serialised.
      pipeline_stage: filters.pipeline_stage || undefined,
      source: filters.source || undefined,
      rating: filters.rating || undefined,
    },
  })
}

export function fetchJob(id: number | string): Promise<JobListing> {
  return api.get<JobListing>(`/jobs/${id}`)
}

export function discoverJobs(input: DiscoverInput = {}): Promise<QueuedResponse> {
  return api.post<QueuedResponse>('/jobs/discover', input)
}

export function rescoreJob(id: number | string): Promise<QueuedResponse> {
  return api.post<QueuedResponse>(`/jobs/${id}/rescore`)
}

export function tailorJob(id: number | string): Promise<QueuedResponse> {
  return api.post<QueuedResponse>(`/jobs/${id}/tailor`)
}

export function applyToJob(id: number | string): Promise<QueuedResponse> {
  return api.post<QueuedResponse>(`/jobs/${id}/apply`)
}

/* ----------------------------------------------------------------- display */

export function jobTitle(listing: JobListing): string {
  const title = listing.title?.trim() ?? ''
  const company = listing.company?.trim() ?? ''
  if (title && company) return `${title} · ${company}`
  return title || company || `Job #${listing.id}`
}

/** The source a row came from; `source_key` is the pipeline's own identifier. */
export function jobSource(listing: JobListing): string | null {
  const source = listing.source_key?.trim() || listing.api_source?.trim()
  if (!source) return null
  return source.replace(/[_-]/g, ' ')
}

/**
 * Salary as one line, or null when neither bound is known.
 *
 * Both bounds are optional in the schema and the currency often is too, so an
 * open-ended range reads as "from"/"up to" rather than inventing a bound.
 */
export function salaryRange(listing: JobListing): string | null {
  const { salary_min: min, salary_max: max, currency } = listing
  if (min == null && max == null) return null

  const format = (value: number) =>
    currency
      ? (() => {
          try {
            return new Intl.NumberFormat(undefined, {
              style: 'currency',
              currency,
              maximumFractionDigits: 0,
            }).format(value)
          } catch {
            // An unrecognised currency code must not break the row.
            return `${value.toLocaleString()} ${currency}`
          }
        })()
      : value.toLocaleString()

  if (min != null && max != null) {
    return min === max ? format(min) : `${format(min)} – ${format(max)}`
  }
  return min != null ? `From ${format(min)}` : `Up to ${format(max as number)}`
}

/** `parsed_skills` is model output; only a list of strings is trusted. */
export function readSkills(raw: unknown): string[] {
  if (!Array.isArray(raw)) return []
  return raw
    .map((entry) => (typeof entry === 'string' ? entry.trim() : null))
    .filter((entry): entry is string => entry !== null && entry.length > 0)
}

const ACTION_LABELS: Record<string, string> = {
  auto_apply: 'Auto-apply',
  store_only: 'Store only',
}

/** `App\Enums\RecommendedAction` — what the scorer advised. */
export function recommendedActionLabel(action: string | null | undefined): string | null {
  if (!action) return null
  return ACTION_LABELS[action] ?? action.replace(/_/g, ' ')
}

/* ----------------------------------------------------------------- polling */

export const JOB_POLL_INTERVAL_MS = 6_000

/**
 * Stages where the pipeline is still expected to move a listing on its own.
 * Mirrors `PipelineStage::isTerminal()`, inverted.
 */
const PENDING_STAGES: readonly string[] = [
  'discovered',
  'enriching',
  'scored',
  'tailoring',
  'tailored',
  'applying',
]

export function isJobPending(stage: string | null | undefined): boolean {
  if (!stage) return false
  return PENDING_STAGES.includes(stage)
}

export function pollIntervalForJobList(
  page: Paginated<JobListing> | undefined,
): number | false {
  if (!page) return false
  return page.data.some((listing) => isJobPending(listing.pipeline_stage))
    ? JOB_POLL_INTERVAL_MS
    : false
}

export function pollIntervalForJob(listing: JobListing | undefined): number | false {
  if (!listing) return false
  return isJobPending(listing.pipeline_stage) ? JOB_POLL_INTERVAL_MS : false
}
