import { createContext } from 'react'

/** Minimal shape of the user returned by `GET /api/me`. */
export interface AuthUser {
  id: number
  name: string
  email: string
}

export type AuthStatus = 'loading' | 'authenticated' | 'unauthenticated'

export interface LoginCredentials {
  email: string
  password: string
}

export interface RegisterCredentials extends LoginCredentials {
  name: string
  password_confirmation: string
}

export interface AuthContextValue {
  /**
   * Derived from `GET /api/me`, never from a stored token — the Sanctum token
   * lives in a httpOnly cookie the frontend cannot read (open decision #6).
   */
  status: AuthStatus
  user: AuthUser | null
  login: (credentials: LoginCredentials) => Promise<AuthUser>
  register: (credentials: RegisterCredentials) => Promise<AuthUser>
  logout: () => Promise<void>
  /** Re-check the session against the backend. */
  refresh: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)

/** Query key for the current-session probe (`GET /api/me`). */
export const meQueryKey = ['auth', 'me'] as const

/** Where a 401 (or an unauthenticated protected-route hit) sends the user. */
export const LOGIN_ROUTE = '/login'

/** Sign-up screen; same guest-only treatment as {@link LOGIN_ROUTE}. */
export const REGISTER_ROUTE = '/register'

/** Landing route once authenticated, and the fallback when there's no `from`. */
export const DEFAULT_AUTHENTICATED_ROUTE = '/'

/**
 * Shape of the router `location.state` used to remember where an
 * unauthenticated user was heading, so login can send them back there.
 * `AuthProvider` already writes this on 401; `ProtectedRoute` writes it too.
 */
export interface AuthRedirectState {
  from?: string
}

/**
 * Read the intended destination out of router state, rejecting anything that
 * isn't a same-origin path (an open-redirect guard, since `state` can be
 * crafted by whatever navigated here).
 */
export function intendedDestination(state: unknown): string {
  const from = (state as AuthRedirectState | null)?.from

  if (typeof from !== 'string' || !from.startsWith('/') || from.startsWith('//')) {
    return DEFAULT_AUTHENTICATED_ROUTE
  }

  // Never bounce back to the auth screens themselves.
  const path = from.split('?')[0]
  if (path === LOGIN_ROUTE || path === REGISTER_ROUTE) {
    return DEFAULT_AUTHENTICATED_ROUTE
  }

  return from
}
