/**
 * Applications API types, query keys, and fetchers (Requirements 10.1–10.4).
 *
 * Mirrors `backend/app/Http/Controllers/Api/ApplicationController`, whose rows
 * are assembled by `App\Support\ApplicationPresenter`:
 * - `GET   /applications`      → paginator page of application summaries
 * - `GET   /applications/{id}` → one application with the JD, score rationale,
 *                                signed document links, and automation log
 * - `PATCH /applications/{id}` → records a manual `response_status` (+ note)
 *                                and returns the same shape as the detail read
 *
 * Two status vocabularies live side by side here and must not be conflated —
 * that separation is the whole point of Requirement 10.4:
 * - `status` / `job_listing.pipeline_stage`: what the automation did.
 * - `response_status`: what the *employer* did, reported by the user.
 */

import { api } from './client'
import type { Paginated } from './resumes'

/* ------------------------------------------------------------ vocabularies */

/** `App\Enums\PipelineStage` — where a listing sits in the pipeline. */
export const PIPELINE_STAGES = [
  'discovered',
  'enriching',
  'scored',
  'tailoring',
  'tailored',
  'applying',
  'applied',
  'failed',
  'needs_review',
  'store_only',
] as const

export type PipelineStage = (typeof PIPELINE_STAGES)[number]

/** `App\Enums\ApplicationResponseStatus` — the user-reported outcome. */
export const RESPONSE_STATUSES = [
  'awaiting_response',
  'acknowledged',
  'interviewing',
  'offer',
  'accepted',
  'rejected',
  'withdrawn',
] as const

export type ResponseStatus = (typeof RESPONSE_STATUSES)[number]

/**
 * Stages where no further work is queued, so polling can stop
 * (`PipelineStage::isTerminal()` on the backend).
 */
const TERMINAL_STAGES: readonly string[] = [
  'applied',
  'failed',
  'needs_review',
  'store_only',
]

/* ------------------------------------------------------------------- types */

export interface ApplicationJobListing {
  id: number
  title: string | null
  company: string | null
  location: string | null
  application_url: string | null
  source_key: string | null
  posted_at: string | null
  pipeline_stage: PipelineStage | string | null
  /** Detail read only. */
  description?: string | null
  parsed_skills?: unknown
  salary_min?: number | null
  salary_max?: number | null
  currency?: string | null
}

export interface ApplicationScore {
  stars: number | null
  recommended_action: string | null
  attempt_number: number | null
  /** Detail read only. */
  rationale?: string | null
  fit_analysis?: unknown
  created_at?: string | null
}

export interface ApplicationDocument {
  id: number
  type: 'resume' | 'cover_letter' | string
  status: string
  template_key: string | null
  generation_model: string | null
  fabrication_flags: unknown
  created_at: string | null
  /** Short-lived signed S3 link, or null with a reason in `download_error`. */
  download_url: string | null
  download_error: string | null
}

/** The `needs_review` call-to-action (Requirement 10.3). */
export interface ApplicationReview {
  kind:
    | 'unanswered_questions'
    | 'possible_fabrication'
    | 'automation_failure'
    | 'tailoring_incomplete'
    | 'unspecified'
    | string
  headline: string
  detail: string
  questions?: string[]
  /** Document types carrying a fabrication finding, e.g. `['resume']`. */
  documents?: string[]
}

export interface ManualStatusEntry {
  response_status?: string
  note?: string | null
  recorded_at?: string
  source?: string
}

export interface ApplicationSummary {
  id: number
  user_id: number
  job_listing_id: number | null
  /** Automation-driven status, never set by the user. */
  status: string | null
  /** User-reported employer response; null until they report one. */
  response_status: ResponseStatus | string | null
  apply_adapter_used: string | null
  applied_at: string | null
  created_at: string | null
  updated_at: string | null
  automation_log: unknown
  metadata: unknown
  job_listing: ApplicationJobListing | null
  score: ApplicationScore | null
  needs_review: boolean
  review: ApplicationReview | null
}

export interface ApplicationDetail extends ApplicationSummary {
  documents: ApplicationDocument[]
  manual_status_history: ManualStatusEntry[]
}

export interface UpdateResponseStatusInput {
  response_status: ResponseStatus
  response_note?: string | null
}

/* -------------------------------------------------------------- query keys */

export const applicationKeys = {
  all: ['applications'] as const,
  list: (page: number) => ['applications', 'list', page] as const,
  detail: (id: number | string) => ['applications', 'detail', String(id)] as const,
}

/* ---------------------------------------------------------------- fetchers */

export function fetchApplications(page: number): Promise<Paginated<ApplicationSummary>> {
  return api.get<Paginated<ApplicationSummary>>('/applications', { query: { page } })
}

export function fetchApplication(id: number | string): Promise<ApplicationDetail> {
  return api.get<ApplicationDetail>(`/applications/${id}`)
}

/**
 * Record a manual employer-response update. The backend rejects a `status` key
 * outright, so the automation's own status can't be driven from here.
 */
export function updateApplicationResponse(
  id: number | string,
  input: UpdateResponseStatusInput,
): Promise<ApplicationDetail> {
  return api.patch<ApplicationDetail>(`/applications/${id}`, input)
}

/* ------------------------------------------------------------------ labels */

const STAGE_LABELS: Record<string, string> = {
  discovered: 'Discovered',
  enriching: 'Enriching',
  scored: 'Scored',
  tailoring: 'Tailoring',
  tailored: 'Tailored',
  applying: 'Applying',
  applied: 'Applied',
  failed: 'Failed',
  needs_review: 'Needs review',
  store_only: 'Saved only',
}

export function stageLabel(stage: string | null | undefined): string {
  if (!stage) return 'Unknown'
  return STAGE_LABELS[stage] ?? stage.replace(/_/g, ' ')
}

/** One line on what a stage means, so no row is a bare unexplained badge. */
export function stageDescription(stage: string | null | undefined): string {
  switch (stage) {
    case 'discovered':
      return 'Found by a job source. Nothing has run on it yet.'
    case 'enriching':
      return 'Fetching the full job description.'
    case 'scored':
      return 'Rated against your profile.'
    case 'tailoring':
      return 'Generating a tailored resume and cover letter.'
    case 'tailored':
      return 'Documents are ready.'
    case 'applying':
      return 'An apply adapter is submitting the application.'
    case 'applied':
      return 'Submission confirmed.'
    case 'failed':
      return 'A pipeline stage gave up. See the automation log.'
    case 'needs_review':
      return 'Paused — this one needs you.'
    case 'store_only':
      return 'Kept for reference; not applied to automatically.'
    default:
      return 'The server reported a stage this app does not recognise.'
  }
}

const RESPONSE_LABELS: Record<string, string> = {
  awaiting_response: 'Awaiting response',
  acknowledged: 'Acknowledged',
  interviewing: 'Interviewing',
  offer: 'Offer',
  accepted: 'Accepted',
  rejected: 'Rejected',
  withdrawn: 'Withdrawn',
}

export function responseStatusLabel(status: string | null | undefined): string {
  if (!status) return 'Not reported'
  return RESPONSE_LABELS[status] ?? status.replace(/_/g, ' ')
}

export function documentTypeLabel(type: string): string {
  switch (type) {
    case 'resume':
      return 'Tailored resume'
    case 'cover_letter':
      return 'Cover letter'
    default:
      return type.replace(/_/g, ' ')
  }
}

/* ----------------------------------------------------------------- polling */

export const APPLICATION_POLL_INTERVAL_MS = 6_000

export function isPendingStage(stage: string | null | undefined): boolean {
  if (!stage) return false
  return !TERMINAL_STAGES.includes(stage)
}

/** Poll a list page while any row's listing is still moving. */
export function pollIntervalForApplicationList(
  page: Paginated<ApplicationSummary> | undefined,
): number | false {
  if (!page) return false
  return page.data.some((row) => isPendingStage(row.job_listing?.pipeline_stage ?? null))
    ? APPLICATION_POLL_INTERVAL_MS
    : false
}

/** Same for one application. */
export function pollIntervalForApplication(
  application: ApplicationDetail | undefined,
): number | false {
  if (!application) return false
  return isPendingStage(application.job_listing?.pipeline_stage ?? null)
    ? APPLICATION_POLL_INTERVAL_MS
    : false
}

/* ----------------------------------------------------------------- display */

/**
 * Star rating as text plus a glyph row. Returned as data rather than JSX so the
 * accessible name (`label`) and the decorative glyphs can't drift apart.
 */
export function starRating(stars: number | null | undefined): {
  label: string
  glyphs: string
} | null {
  if (typeof stars !== 'number' || !Number.isFinite(stars)) return null
  const clamped = Math.max(0, Math.min(5, Math.round(stars)))
  return {
    label: `${clamped} of 5 stars`,
    glyphs: '★'.repeat(clamped) + '☆'.repeat(5 - clamped),
  }
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return 'Unknown date'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return parsed.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function applicationTitle(application: ApplicationSummary): string {
  const listing = application.job_listing
  if (!listing) return `Application #${application.id}`
  const title = listing.title?.trim() ?? ''
  const company = listing.company?.trim() ?? ''
  if (title && company) return `${title} · ${company}`
  return title || company || `Application #${application.id}`
}

/* ------------------------------------------------------- automation log */

export interface AutomationLogView {
  adapter: string | null
  steps: string[]
  unansweredQuestions: string[]
  failureReason: string | null
  screenshots: string[]
  /** Anything recognised at all? If not, the raw JSON is shown instead. */
  isEmpty: boolean
}

function asRecord(value: unknown): Record<string, unknown> | null {
  return value && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null
}

function asText(value: unknown): string | null {
  return typeof value === 'string' && value.trim().length > 0 ? value.trim() : null
}

/** Strings, or `{question|label|field|step|description}` maps, flattened. */
function asStringList(value: unknown): string[] {
  if (typeof value === 'string') {
    const single = asText(value)
    return single ? [single] : []
  }
  if (!Array.isArray(value)) return []
  return value
    .map((entry) => {
      const record = asRecord(entry)
      if (!record) return asText(entry)
      return (
        asText(record.question) ??
        asText(record.label) ??
        asText(record.step) ??
        asText(record.description) ??
        asText(record.field) ??
        asText(record.message)
      )
    })
    .filter((entry): entry is string => entry !== null)
}

function pick(record: Record<string, unknown>, ...keys: string[]): unknown {
  for (const key of keys) {
    if (record[key] !== undefined) return record[key]
  }
  return undefined
}

/**
 * Normalise `automation_log` for display (Requirement 10.2 — "what the adapter
 * did, and any errors/screenshots").
 *
 * The shape is per-adapter by design (see the column's migration), and the
 * adapters themselves land in task 15, so nothing here assumes a key exists:
 * both snake_case and the `ApplyResult` camelCase spellings are accepted, and
 * an unrecognised log is reported as empty so the caller can fall back to
 * showing the raw JSON rather than silently rendering nothing.
 */
export function readAutomationLog(raw: unknown): AutomationLogView {
  const record = asRecord(raw)

  if (!record) {
    return {
      adapter: null,
      steps: [],
      unansweredQuestions: [],
      failureReason: null,
      screenshots: [],
      isEmpty: true,
    }
  }

  const adapter = asText(pick(record, 'adapter', 'adapter_used', 'adapterUsed'))
  const steps = asStringList(pick(record, 'steps', 'log', 'events'))
  const unansweredQuestions = asStringList(
    pick(record, 'unanswered_questions', 'unansweredQuestions'),
  )
  const failureReason = asText(pick(record, 'failure_reason', 'failureReason', 'error'))
  const screenshots = asStringList(
    pick(record, 'screenshots', 'screenshot_paths', 'screenshotPaths'),
  )

  return {
    adapter,
    steps,
    unansweredQuestions,
    failureReason,
    screenshots,
    isEmpty:
      adapter === null &&
      steps.length === 0 &&
      unansweredQuestions.length === 0 &&
      failureReason === null &&
      screenshots.length === 0,
  }
}

/** True when the server stored an automation log worth rendering at all. */
export function hasAutomationLog(raw: unknown): boolean {
  if (raw === null || raw === undefined) return false
  const record = asRecord(raw)
  if (record) return Object.keys(record).length > 0
  return Array.isArray(raw) ? raw.length > 0 : true
}

/* ------------------------------------------------------------ fit analysis */

/** `fit_analysis` is raw model output; only labelled string lists are read. */
export function readFitAnalysis(raw: unknown): { label: string; items: string[] }[] {
  const record = asRecord(raw)
  if (!record) return []

  const sections: { label: string; items: string[] }[] = []

  for (const [key, value] of Object.entries(record)) {
    const items = asStringList(value)
    if (items.length === 0) continue
    sections.push({
      label: key.replace(/[_-]/g, ' ').replace(/^./, (c) => c.toUpperCase()),
      items,
    })
  }

  return sections
}
