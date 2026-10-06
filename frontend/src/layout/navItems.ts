import {
  APPLICATIONS_ROUTE,
  AUTOMATION_SETTINGS_ROUTE,
  DASHBOARD_ROUTE,
  JOBS_ROUTE,
  PROFILE_ROUTE,
  RESUMES_ROUTE,
} from '../routes/paths'

export interface NavItem {
  to: string
  label: string
  /**
   * `true` when the link should only be active on an exact path match. The
   * dashboard lives at `/`, which prefix-matches everything, so it needs this.
   */
  end?: boolean
}

/** Primary navigation destinations, in display order (Requirement 1.4). */
export const NAV_ITEMS: readonly NavItem[] = [
  { to: DASHBOARD_ROUTE, label: 'Dashboard', end: true },
  { to: RESUMES_ROUTE, label: 'Resumes' },
  { to: JOBS_ROUTE, label: 'Jobs' },
  { to: APPLICATIONS_ROUTE, label: 'Applications' },
  { to: PROFILE_ROUTE, label: 'Profile' },
  { to: AUTOMATION_SETTINGS_ROUTE, label: 'Automation' },
]
