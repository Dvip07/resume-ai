/**
 * Automation settings API types and fetchers (Requirements 9.1, 9.3, 9.7).
 *
 * Mirrors `backend/app/Http/Controllers/Api/AutomationSettingController`:
 * - `GET   /automation-settings` → always 200. A user who has never saved
 *   anything gets the platform defaults (everything off), so there is no
 *   "missing row" state for this screen to handle.
 * - `PATCH /automation-settings` → partial update; only the keys sent change.
 *
 * ## The LinkedIn acknowledgment
 *
 * Switching `linkedin_auto_apply_opt_in` on requires
 * `linkedin_risk_acknowledged: true` in the same request, and the server
 * enforces that — a 422 on `linkedin_risk_acknowledged` is what comes back
 * otherwise. The flag is per-request and never stored: it is a statement about
 * this change, so turning the setting off and on again asks again.
 *
 * Turning it **off** needs no acknowledgment. Withdrawing consent is always one
 * click.
 */

import { api } from './client'

export interface AutomationSettings {
  auto_apply_enabled: boolean
  /** 1–5. Jobs scored below this are never auto-applied to. */
  auto_apply_star_threshold: number
  linkedin_auto_apply_opt_in: boolean
  daily_apply_cap: number
  linkedin_daily_apply_cap: number
  linkedin: {
    /** The `user_automation_settings` column the apply pipeline checks. */
    opt_in_setting: string
    /** The platform's own ceiling, which a user can go under but not over. */
    platform_daily_cap: number
    acknowledgment_required: boolean
  }
}

/** Partial update. Only the keys present are changed. */
export interface AutomationSettingsPayload {
  auto_apply_enabled?: boolean
  auto_apply_star_threshold?: number
  linkedin_auto_apply_opt_in?: boolean
  daily_apply_cap?: number
  linkedin_daily_apply_cap?: number
  /** Required by the server when switching the LinkedIn opt-in on. */
  linkedin_risk_acknowledged?: boolean
}

export const automationSettingsKeys = {
  detail: ['automation-settings'] as const,
}

export function fetchAutomationSettings(): Promise<AutomationSettings> {
  return api.get<AutomationSettings>('/automation-settings')
}

export function updateAutomationSettings(
  payload: AutomationSettingsPayload,
): Promise<AutomationSettings> {
  return api.patch<AutomationSettings>('/automation-settings', payload)
}

/**
 * What the user is agreeing to, in the words they have to agree to. Exported so
 * the screen and any future confirmation surface cannot drift into saying
 * different things about the same risk.
 */
export const LINKEDIN_RISKS = [
  'Applications are submitted through your own signed-in LinkedIn session.',
  "Automating Easy Apply may breach LinkedIn's User Agreement.",
  'LinkedIn may restrict or permanently close your account as a result.',
  'This platform cannot recover a restricted LinkedIn account for you.',
] as const
