import { DEFAULT_AUTHENTICATED_ROUTE } from '../auth/authContext'

/**
 * Authenticated route paths (Requirement 1.4). Kept in one module so the route
 * table, the primary nav, and any in-app links can't drift apart.
 *
 * Auth-screen paths deliberately stay in `auth/authContext` — the auth layer
 * needs them for its redirect logic and must not depend on app routing.
 */

/** Landing screen once signed in; same path the login redirect falls back to. */
export const DASHBOARD_ROUTE = DEFAULT_AUTHENTICATED_ROUTE

export const RESUMES_ROUTE = '/resumes'

/** Resume detail sits under the list, so `/resumes` stays nav-active on it. */
export const RESUME_DETAIL_ROUTE = '/resumes/:resumeId'

export const JOBS_ROUTE = '/jobs'

/** Job detail is a child of the jobs list, so `/jobs` stays nav-active on it. */
export const JOB_DETAIL_ROUTE = '/jobs/:jobId'

export const APPLICATIONS_ROUTE = '/applications'

/**
 * Application detail sits under the list, so `/applications` stays nav-active
 * on it.
 */
export const APPLICATION_DETAIL_ROUTE = '/applications/:applicationId'

export const PROFILE_ROUTE = '/profile'

/** Automation consent and throttles, including the LinkedIn opt-in (Req 9.3). */
export const AUTOMATION_SETTINGS_ROUTE = '/settings/automation'

/** Build a concrete resume-detail path from an id. */
export function resumeDetailPath(resumeId: number | string): string {
  return `${RESUMES_ROUTE}/${resumeId}`
}

/** Build a concrete job-detail path from an id. */
export function jobDetailPath(jobId: number | string): string {
  return `${JOBS_ROUTE}/${jobId}`
}

/** Build a concrete application-detail path from an id. */
export function applicationDetailPath(applicationId: number | string): string {
  return `${APPLICATIONS_ROUTE}/${applicationId}`
}
