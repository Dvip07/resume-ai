/**
 * Resume API types, query keys, and fetchers (Requirement 2.4, 2.5).
 *
 * Mirrors `backend/app/Http/Controllers/Api/ResumeController`:
 * - `GET    /resumes`      → Laravel paginator page of resumes
 * - `POST   /resumes`      → multipart `resume` field, 201 + created record
 * - `GET    /resumes/{id}` → single record (404 when not owned by the caller)
 * - `DELETE /resumes/{id}` → `{ message }`
 *
 * `parsed_data` is raw LLM output persisted as JSON, so nothing about its shape
 * is guaranteed. The readers at the bottom of this module normalise the shapes
 * the parsing prompt asks for (`skills.primary`/`secondary`, `workExperience`,
 * `education` as either an object of parallel arrays or a list, `keywords`,
 * `summary`) and return empty results rather than throwing on anything else.
 */

import { api } from './client'

/** `App\Enums\ResumeStatus` — the full set the backend can persist. */
export const RESUME_STATUSES = ['uploaded', 'parsing', 'parsed', 'failed'] as const

export type ResumeStatus = (typeof RESUME_STATUSES)[number]

/**
 * Statuses that will not change on their own. Anything else means a queued job
 * is still expected to move the record along, which is what drives polling
 * (Requirement 2.4 — no silent "processing" state).
 */
const TERMINAL_STATUSES: readonly ResumeStatus[] = ['parsed', 'failed']

export interface Resume {
  id: number
  user_id: number
  original_filename: string | null
  file_path: string | null
  storage_disk: string | null
  status: ResumeStatus | string
  status_error: string | null
  parsed_data: unknown
  created_at: string | null
  updated_at: string | null
}

/** The slice of Laravel's paginator payload the list screen needs. */
export interface Paginated<T> {
  data: T[]
  current_page: number
  per_page: number
  last_page: number
  total: number
}

export interface UploadResumeResponse {
  message?: string
  resume: Resume
}

export const resumeKeys = {
  all: ['resumes'] as const,
  list: (page: number) => ['resumes', 'list', page] as const,
  detail: (id: number | string) => ['resumes', 'detail', String(id)] as const,
}

export function fetchResumes(page: number): Promise<Paginated<Resume>> {
  return api.get<Paginated<Resume>>('/resumes', { query: { page } })
}

export function fetchResume(id: number | string): Promise<Resume> {
  return api.get<Resume>(`/resumes/${id}`)
}

export function uploadResume(file: File): Promise<UploadResumeResponse> {
  const form = new FormData()
  // Field name must match the backend validator key.
  form.append('resume', file)
  return api.post<UploadResumeResponse>('/resumes', form)
}

export function deleteResume(id: number | string): Promise<{ message?: string }> {
  return api.delete<{ message?: string }>(`/resumes/${id}`)
}

/* ------------------------------------------------------------------ status */

export function isTerminalStatus(status: Resume['status']): boolean {
  return (TERMINAL_STATUSES as readonly string[]).includes(status)
}

/** True while a queued job is still expected to change this record. */
export function isPendingStatus(status: Resume['status']): boolean {
  return !isTerminalStatus(status)
}

/** How often to re-check a record that hasn't settled yet. */
export const RESUME_POLL_INTERVAL_MS = 4_000

/**
 * `refetchInterval` value for a single resume: poll while pending, stop once
 * the status is terminal. `false` disables polling in TanStack Query.
 */
export function pollIntervalForResume(resume: Resume | undefined): number | false {
  if (!resume) return false
  return isPendingStatus(resume.status) ? RESUME_POLL_INTERVAL_MS : false
}

/** Same, for a list page: poll while *any* row on the page is pending. */
export function pollIntervalForResumeList(page: Paginated<Resume> | undefined): number | false {
  if (!page) return false
  return page.data.some((resume) => isPendingStatus(resume.status))
    ? RESUME_POLL_INTERVAL_MS
    : false
}

/** Short human-readable label for a status value. */
export function statusLabel(status: Resume['status']): string {
  switch (status) {
    case 'uploaded':
      return 'Uploaded'
    case 'parsing':
      return 'Parsing'
    case 'parsed':
      return 'Parsed'
    case 'failed':
      return 'Failed'
    default:
      return String(status)
  }
}

/** One-line explanation of what a status means, shown next to the badge. */
export function statusDescription(resume: Resume): string {
  switch (resume.status) {
    case 'uploaded':
      return 'Stored. Waiting for the parser to pick it up.'
    case 'parsing':
      return 'Extracting skills, experience, and education.'
    case 'parsed':
      return 'Parsing finished. Extracted details are below.'
    case 'failed':
      return resume.status_error ?? 'Parsing failed. No reason was recorded.'
    default:
      return 'Status reported by the server is not recognised by this app.'
  }
}

/* ------------------------------------------------------------- parsed_data */

function asRecord(value: unknown): Record<string, unknown> | null {
  return value && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null
}

/** Keep only non-empty strings, from either a string list or a single string. */
function asStringList(value: unknown): string[] {
  if (typeof value === 'string') {
    return value.trim() ? [value.trim()] : []
  }
  if (!Array.isArray(value)) return []
  return value
    .map((entry) => (typeof entry === 'string' ? entry.trim() : ''))
    .filter((entry) => entry.length > 0)
}

function asText(value: unknown): string | null {
  return typeof value === 'string' && value.trim().length > 0 ? value.trim() : null
}

/** Case-insensitive key read, since the LLM output casing isn't stable. */
function pick(record: Record<string, unknown>, ...keys: string[]): unknown {
  const lowered = new Map(Object.keys(record).map((key) => [key.toLowerCase(), key]))
  for (const key of keys) {
    const actual = lowered.get(key.toLowerCase())
    if (actual !== undefined) return record[actual]
  }
  return undefined
}

export interface ParsedSkills {
  primary: string[]
  secondary: string[]
  other: string[]
}

export interface ParsedExperience {
  company: string | null
  position: string | null
  duration: string | null
  achievements: string[]
}

export interface ParsedEducation {
  institution: string | null
  degree: string | null
  fieldOfStudy: string | null
  years: string | null
}

export interface ParsedResumeData {
  summary: string | null
  skills: ParsedSkills
  experience: ParsedExperience[]
  education: ParsedEducation[]
  keywords: string[]
  /** True when nothing renderable was found — the raw JSON is shown instead. */
  isEmpty: boolean
}

function readSkills(value: unknown): ParsedSkills {
  const empty: ParsedSkills = { primary: [], secondary: [], other: [] }

  if (Array.isArray(value)) {
    return { ...empty, other: asStringList(value) }
  }

  const record = asRecord(value)
  if (!record) return empty

  const primary = asStringList(pick(record, 'primary'))
  const secondary = asStringList(pick(record, 'secondary'))

  // Any other keys (e.g. "tools", "languages") still carry useful skills.
  const other: string[] = []
  for (const [key, entry] of Object.entries(record)) {
    const lowered = key.toLowerCase()
    if (lowered === 'primary' || lowered === 'secondary') continue
    other.push(...asStringList(entry))
  }

  return { primary, secondary, other }
}

function durationText(value: unknown): string | null {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return `${value} month${value === 1 ? '' : 's'}`
  }
  return asText(value)
}

function readExperience(value: unknown): ParsedExperience[] {
  if (!Array.isArray(value)) return []

  return value
    .map((entry): ParsedExperience | null => {
      const record = asRecord(entry)
      if (!record) {
        const text = asText(entry)
        return text
          ? { company: text, position: null, duration: null, achievements: [] }
          : null
      }

      return {
        company: asText(pick(record, 'companyName', 'company', 'employer')),
        position: asText(pick(record, 'position', 'title', 'role')),
        duration: durationText(pick(record, 'duration', 'durationMonths', 'dates')),
        achievements: asStringList(
          pick(record, 'achievements', 'highlights', 'responsibilities'),
        ),
      }
    })
    .filter((entry): entry is ParsedExperience => entry !== null)
}

function yearRange(start: unknown, end: unknown): string | null {
  const from = asText(start) ?? (typeof start === 'number' ? String(start) : null)
  const to = asText(end) ?? (typeof end === 'number' ? String(end) : null)
  if (from && to) return `${from} – ${to}`
  return from ?? to
}

function readEducation(value: unknown): ParsedEducation[] {
  // Shape A: a list of entries.
  if (Array.isArray(value)) {
    return value
      .map((entry): ParsedEducation | null => {
        const record = asRecord(entry)
        if (!record) {
          const text = asText(entry)
          return text
            ? { institution: text, degree: null, fieldOfStudy: null, years: null }
            : null
        }

        const years = asRecord(pick(record, 'yearsAttended'))
        return {
          institution: asText(pick(record, 'institution', 'school', 'university')),
          degree: asText(pick(record, 'degree', 'qualification')),
          fieldOfStudy: asText(pick(record, 'fieldOfStudy', 'field', 'major')),
          years: years
            ? yearRange(pick(years, 'startYear'), pick(years, 'endYear'))
            : yearRange(pick(record, 'startYear'), pick(record, 'endYear')),
        }
      })
      .filter((entry): entry is ParsedEducation => entry !== null)
  }

  // Shape B: an object of parallel arrays, which is what the parsing prompt
  // asks the model for.
  const record = asRecord(value)
  if (!record) return []

  const institutions = asStringList(pick(record, 'institution', 'institutions'))
  const degrees = asStringList(pick(record, 'degree', 'degrees'))
  const fields = asStringList(pick(record, 'fieldOfStudy', 'fieldsOfStudy'))
  const yearsRaw = pick(record, 'yearsAttended')
  const years = Array.isArray(yearsRaw) ? yearsRaw : []

  const count = Math.max(institutions.length, degrees.length, fields.length, years.length)

  return Array.from({ length: count }, (_, index) => {
    const yearEntry = asRecord(years[index])
    return {
      institution: institutions[index] ?? null,
      degree: degrees[index] ?? null,
      fieldOfStudy: fields[index] ?? null,
      years: yearEntry
        ? yearRange(pick(yearEntry, 'startYear'), pick(yearEntry, 'endYear'))
        : null,
    }
  })
}

/** Normalise `parsed_data` into the fields the detail screen renders. */
export function readParsedData(raw: unknown): ParsedResumeData {
  const record = asRecord(raw)

  const summary = record ? asText(pick(record, 'summary', 'professionalSummary')) : null
  const skills = record ? readSkills(pick(record, 'skills')) : { primary: [], secondary: [], other: [] }
  const experience = record
    ? readExperience(pick(record, 'workExperience', 'experience'))
    : []
  const education = record ? readEducation(pick(record, 'education')) : []
  const keywords = record ? asStringList(pick(record, 'keywords')) : []

  const isEmpty =
    !summary &&
    skills.primary.length === 0 &&
    skills.secondary.length === 0 &&
    skills.other.length === 0 &&
    experience.length === 0 &&
    education.length === 0 &&
    keywords.length === 0

  return { summary, skills, experience, education, keywords, isEmpty }
}

/** `true` when the server stored nothing at all for `parsed_data`. */
export function hasParsedData(resume: Resume): boolean {
  if (resume.parsed_data === null || resume.parsed_data === undefined) return false
  const record = asRecord(resume.parsed_data)
  if (record) return Object.keys(record).length > 0
  return Array.isArray(resume.parsed_data) ? resume.parsed_data.length > 0 : true
}

/* ---------------------------------------------------------- upload guards */

/** Backend validator: `mimes:pdf`, `max:10240` (kilobytes). */
export const MAX_RESUME_BYTES = 10 * 1024 * 1024
export const ACCEPTED_RESUME_TYPE = 'application/pdf'

/**
 * Client-side mirror of the backend's file rules, so an obviously invalid file
 * is rejected before a 10 MB upload is attempted. The server still validates —
 * this is a convenience, not the guarantee.
 */
export function validateResumeFile(file: File | null): string | null {
  if (!file) return 'Choose a PDF resume to upload.'

  const isPdf =
    file.type === ACCEPTED_RESUME_TYPE || file.name.toLowerCase().endsWith('.pdf')
  if (!isPdf) return 'Resumes must be PDF files.'

  if (file.size === 0) return 'That file is empty. Choose a different PDF.'

  if (file.size > MAX_RESUME_BYTES) {
    return `That file is ${formatBytes(file.size)}. The limit is 10 MB.`
  }

  return null
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/** Upload timestamps as a readable local date/time; falls back to the raw value. */
export function formatUploadedAt(value: string | null): string {
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
