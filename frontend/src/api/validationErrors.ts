/**
 * Helpers for Laravel's 422 validation-error payload.
 *
 * Laravel responds to a failed `$request->validate()` with:
 * ```json
 * { "message": "…", "errors": { "email": ["…"], "password": ["…"] } }
 * ```
 * These helpers turn that into a flat `field -> first message` map the auth
 * forms can render per-input, and give a single summary message for anything
 * that isn't field-scoped (network failure, 500, throttling).
 */

import { ApiError } from './client'

/** One message per field — forms only have room to show the first. */
export type FieldErrors<TField extends string = string> = Partial<Record<TField, string>>

export const VALIDATION_STATUS = 422

interface LaravelValidationBody {
  message?: unknown
  errors?: unknown
}

function firstMessage(value: unknown): string | null {
  if (typeof value === 'string' && value.length > 0) return value
  if (Array.isArray(value)) {
    const first = value.find((entry) => typeof entry === 'string' && entry.length > 0)
    return typeof first === 'string' ? first : null
  }
  return null
}

/**
 * Extract per-field messages from a 422 response. Returns an empty object for
 * any other error, so callers can fall back to `summaryMessage`.
 *
 * `allowedFields` keeps unknown server-side keys from being dropped silently:
 * anything not in the list is reported through `unmappedErrors` instead.
 */
export function fieldErrorsFrom<TField extends string>(
  error: unknown,
  allowedFields: readonly TField[],
): { fieldErrors: FieldErrors<TField>; unmappedErrors: string[] } {
  const fieldErrors: FieldErrors<TField> = {}
  const unmappedErrors: string[] = []

  if (!(error instanceof ApiError) || error.status !== VALIDATION_STATUS) {
    return { fieldErrors, unmappedErrors }
  }

  const body = error.body as LaravelValidationBody | null
  const errors = body?.errors

  if (!errors || typeof errors !== 'object') {
    return { fieldErrors, unmappedErrors }
  }

  for (const [key, value] of Object.entries(errors as Record<string, unknown>)) {
    const message = firstMessage(value)
    if (!message) continue

    if ((allowedFields as readonly string[]).includes(key)) {
      fieldErrors[key as TField] = message
    } else {
      unmappedErrors.push(message)
    }
  }

  return { fieldErrors, unmappedErrors }
}

/**
 * Every field message from a 422, keyed by the exact server-side key —
 * including dotted paths for nested input like `experience.0.company` or
 * `skills.primary.2`.
 *
 * Unlike `fieldErrorsFrom`, nothing is filtered out: forms with dynamic,
 * index-based field names can't enumerate their keys up front, so they look up
 * messages by key and report whatever is left over themselves.
 */
export function allFieldErrorsFrom(error: unknown): Record<string, string> {
  const result: Record<string, string> = {}

  if (!(error instanceof ApiError) || error.status !== VALIDATION_STATUS) {
    return result
  }

  const body = error.body as LaravelValidationBody | null
  const errors = body?.errors

  if (!errors || typeof errors !== 'object') return result

  for (const [key, value] of Object.entries(errors as Record<string, unknown>)) {
    const message = firstMessage(value)
    if (message) result[key] = message
  }

  return result
}

/**
 * A single human-readable message for the form-level error region. Returns
 * `null` when the error was fully explained by per-field messages.
 */
export function summaryMessage(error: unknown, hasFieldErrors: boolean): string | null {
  if (error instanceof ApiError) {
    if (error.status === VALIDATION_STATUS && hasFieldErrors) return null
    if (error.status === VALIDATION_STATUS) {
      return error.message || 'Please correct the highlighted fields.'
    }
    if (error.status === 429) {
      return 'Too many attempts. Please wait a moment and try again.'
    }
    if (error.status >= 500) {
      return 'The server had a problem handling that. Please try again.'
    }
    return error.message
  }

  if (error instanceof Error) {
    // fetch() rejects like this when the backend is down or CORS blocks us.
    return 'Could not reach the server. Check your connection and try again.'
  }

  return 'Something went wrong. Please try again.'
}
