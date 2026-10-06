/**
 * Credential handling for the API client.
 *
 * Open decision #6 is resolved: the Sanctum token lives in a **backend-set
 * httpOnly cookie**, so it is deliberately NOT readable from JavaScript. There
 * is therefore nothing for the frontend to store or attach — the browser sends
 * the cookie automatically as long as every request uses
 * `credentials: 'include'` (see `client.ts`) and carries the
 * `X-Requested-With: XMLHttpRequest` header the backend requires as its CSRF
 * mitigation.
 *
 * What remains here:
 * - `CREDENTIALS_MODE` / `REQUESTED_WITH_HEADER`: the single place describing
 *   how credentials travel, consumed by `client.ts`.
 * - An optional **in-memory** bearer override, kept only for non-cookie flows
 *   (tests, CLI-ish tooling, or a non-browser embedder). It never touches
 *   `localStorage`/`sessionStorage`, so it adds no XSS-persistable surface.
 *
 * Authenticated/unauthenticated state is derived from `GET /api/me`
 * (see `src/auth/AuthProvider.tsx`), never from the presence of a token.
 */

export const CREDENTIALS_MODE: RequestCredentials = 'include'

export const REQUESTED_WITH_HEADER = {
  name: 'X-Requested-With',
  value: 'XMLHttpRequest',
} as const

/** In-memory only. Lost on reload by design — the cookie is the real session. */
let inMemoryBearerToken: string | null = null

/**
 * Set an explicit bearer token for non-cookie flows. Browser code should not
 * need this; the httpOnly cookie covers the normal login path.
 */
export function setBearerToken(token: string | null): void {
  inMemoryBearerToken = token
}

export function getBearerToken(): string | null {
  return inMemoryBearerToken
}

export function clearBearerToken(): void {
  inMemoryBearerToken = null
}
