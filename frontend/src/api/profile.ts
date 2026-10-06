/**
 * Profile API types, fetchers, and form <-> payload mapping (Requirement 2.5).
 *
 * Mirrors `backend/app/Http/Controllers/Api/ProfileController`:
 * - `GET   /profile` → the profile row, or **404** when the user has never had
 *   one created. 404 is a normal state (no resume parsed yet, nothing entered
 *   manually), not an error — see `isProfileMissing`.
 * - `PATCH /profile` → validates and persists, creating the row if needed.
 *
 * The JSON columns are written by two different producers: the resume parser
 * (`App\Jobs\AnalyzeResumeJob::updateUserProfile`) and this screen. The parser
 * writes `skills` as `{ primary, secondary }`, but older rows can hold a flat
 * list of strings, so reads normalise both into the editable shape below.
 */

import { api, ApiError } from './client'

export interface ProfileSkills {
  primary: string[]
  secondary: string[]
}

export interface ProfileLocation {
  city: string
  state: string
  country: string
}

export interface ProfileExperience {
  company: string
  position: string
  /** Free text: the parser writes a month count, users often type "2 years". */
  duration: string
  achievements: string[]
}

export interface ProfileEducation {
  institution: string
  degree: string
  fieldOfStudy: string
  startYear: string
  endYear: string
}

/** The raw row as returned by the API. JSON columns are unvalidated on read. */
export interface Profile {
  id: number
  user_id: number
  skills: unknown
  location: unknown
  linkedin_url: string | null
  github_url: string | null
  portfolio_url: string | null
  suggested_roles: unknown
  experience: unknown
  education: unknown
  parsed_keywords: string | null
  resume_text: string | null
  created_at: string | null
  updated_at: string | null
}

/** Editable state for the whole screen — all strings, so inputs stay controlled. */
export interface ProfileForm {
  skills: ProfileSkills
  location: ProfileLocation
  linkedin_url: string
  github_url: string
  portfolio_url: string
  experience: ProfileExperience[]
  education: ProfileEducation[]
}

export const profileKeys = {
  detail: ['profile'] as const,
}

export function fetchProfile(): Promise<Profile> {
  return api.get<Profile>('/profile')
}

export function updateProfile(payload: ProfilePayload): Promise<Profile> {
  return api.patch<Profile>('/profile', payload)
}

/** True for the "no profile row yet" 404, which the screen treats as empty. */
export function isProfileMissing(error: unknown): boolean {
  return error instanceof ApiError && error.status === 404
}

/* -------------------------------------------------------------- read side */

function asRecord(value: unknown): Record<string, unknown> | null {
  return value && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null
}

/** Any scalar becomes display text; objects/arrays become empty. */
function asText(value: unknown): string {
  if (typeof value === 'string') return value
  if (typeof value === 'number' && Number.isFinite(value)) return String(value)
  return ''
}

function asTextList(value: unknown): string[] {
  if (!Array.isArray(value)) return []
  return value.map(asText).filter((entry) => entry.trim().length > 0)
}

export const EMPTY_EXPERIENCE: ProfileExperience = {
  company: '',
  position: '',
  duration: '',
  achievements: [],
}

export const EMPTY_EDUCATION: ProfileEducation = {
  institution: '',
  degree: '',
  fieldOfStudy: '',
  startYear: '',
  endYear: '',
}

export const EMPTY_PROFILE_FORM: ProfileForm = {
  skills: { primary: [], secondary: [] },
  location: { city: '', state: '', country: '' },
  linkedin_url: '',
  github_url: '',
  portfolio_url: '',
  experience: [],
  education: [],
}

/**
 * `skills` reads as `{ primary, secondary }`. A legacy flat list is folded into
 * `primary` so it stays editable instead of being dropped; saving then
 * normalises the row to the grouped shape the parser writes.
 */
function readSkills(value: unknown): ProfileSkills {
  if (Array.isArray(value)) {
    return { primary: asTextList(value), secondary: [] }
  }

  const record = asRecord(value)
  if (!record) return { primary: [], secondary: [] }

  return {
    primary: asTextList(record.primary),
    secondary: asTextList(record.secondary),
  }
}

function readLocation(value: unknown): ProfileLocation {
  const record = asRecord(value)
  if (!record) return { city: '', state: '', country: '' }

  return {
    city: asText(record.city),
    state: asText(record.state),
    country: asText(record.country),
  }
}

function readExperience(value: unknown): ProfileExperience[] {
  if (!Array.isArray(value)) return []

  return value.map((entry): ProfileExperience => {
    const record = asRecord(entry)
    if (!record) return { ...EMPTY_EXPERIENCE, company: asText(entry) }

    return {
      company: asText(record.company),
      position: asText(record.position),
      duration: asText(record.duration),
      achievements: asTextList(record.achievements),
    }
  })
}

function readEducation(value: unknown): ProfileEducation[] {
  if (!Array.isArray(value)) return []

  return value.map((entry): ProfileEducation => {
    const record = asRecord(entry)
    if (!record) return { ...EMPTY_EDUCATION, institution: asText(entry) }

    return {
      institution: asText(record.institution),
      degree: asText(record.degree),
      fieldOfStudy: asText(record.fieldOfStudy),
      startYear: asText(record.startYear),
      endYear: asText(record.endYear),
    }
  })
}

/** Turn a fetched profile into editable form state. */
export function toProfileForm(profile: Profile | undefined): ProfileForm {
  if (!profile) return EMPTY_PROFILE_FORM

  return {
    skills: readSkills(profile.skills),
    location: readLocation(profile.location),
    linkedin_url: profile.linkedin_url ?? '',
    github_url: profile.github_url ?? '',
    portfolio_url: profile.portfolio_url ?? '',
    experience: readExperience(profile.experience),
    education: readEducation(profile.education),
  }
}

/* ------------------------------------------------------------- write side */

export interface ProfilePayload {
  skills: { primary: string[]; secondary: string[] }
  location: { city: string | null; state: string | null; country: string | null }
  linkedin_url: string
  github_url: string
  portfolio_url: string
  experience: Array<{
    company: string | null
    position: string | null
    duration: string | null
    achievements: string[]
  }>
  education: Array<{
    institution: string | null
    degree: string | null
    fieldOfStudy: string | null
    startYear: number | string | null
    endYear: number | string | null
  }>
}

/** Blank optional text is sent as null, which the backend accepts everywhere. */
function orNull(value: string): string | null {
  const trimmed = value.trim()
  return trimmed.length > 0 ? trimmed : null
}

/**
 * Years are `integer` server-side. Digits are sent as numbers; anything else
 * non-blank is sent through untouched so the server owns the rejection and the
 * message lands on `education.N.startYear` rather than being invented here.
 */
function orNullYear(value: string): number | string | null {
  const trimmed = value.trim()
  if (trimmed.length === 0) return null
  return /^\d+$/.test(trimmed) ? Number(trimmed) : trimmed
}

function cleanList(items: string[]): string[] {
  return items.map((item) => item.trim()).filter((item) => item.length > 0)
}

/**
 * Serialise the form for `PATCH /profile`. Every editable field is always
 * sent, so clearing a value actually clears it — a partial patch would leave
 * stale parser output in place.
 *
 * Index order is preserved exactly, because the server reports validation
 * errors by index (`experience.0.company`) and the form maps them back by the
 * same index.
 */
export function toProfilePayload(form: ProfileForm): ProfilePayload {
  return {
    skills: {
      primary: cleanList(form.skills.primary),
      secondary: cleanList(form.skills.secondary),
    },
    location: {
      city: orNull(form.location.city),
      state: orNull(form.location.state),
      country: orNull(form.location.country),
    },
    // Sent as '' rather than null: these columns are NOT NULL, and the
    // controller normalises a cleared link to an empty string anyway.
    linkedin_url: form.linkedin_url.trim(),
    github_url: form.github_url.trim(),
    portfolio_url: form.portfolio_url.trim(),
    experience: form.experience.map((entry) => ({
      company: orNull(entry.company),
      position: orNull(entry.position),
      duration: orNull(entry.duration),
      achievements: cleanList(entry.achievements),
    })),
    education: form.education.map((entry) => ({
      institution: orNull(entry.institution),
      degree: orNull(entry.degree),
      fieldOfStudy: orNull(entry.fieldOfStudy),
      startYear: orNullYear(entry.startYear),
      endYear: orNullYear(entry.endYear),
    })),
  }
}

/** Human summary of an experience entry, for the collapsed heading/labels. */
export function experienceLabel(entry: ProfileExperience, index: number): string {
  const parts = [entry.position, entry.company].filter((part) => part.trim().length > 0)
  return parts.length > 0 ? parts.join(' at ') : `Experience ${index + 1}`
}

/** Human summary of an education entry, for the heading/labels. */
export function educationLabel(entry: ProfileEducation, index: number): string {
  const parts = [entry.degree, entry.institution].filter((part) => part.trim().length > 0)
  return parts.length > 0 ? parts.join(' — ') : `Education ${index + 1}`
}
