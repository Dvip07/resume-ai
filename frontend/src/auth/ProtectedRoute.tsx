import type { ReactNode } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'

import { LOGIN_ROUTE } from './authContext'
import { useAuth } from './useAuth'

/**
 * Gate for authenticated-only routes (Requirement 1.6).
 *
 * - `loading` → render a polite waiting state. Redirecting during the initial
 *   `GET /api/me` probe would bounce a signed-in user to /login on every
 *   reload.
 * - `unauthenticated` → redirect to /login, recording the intended
 *   destination in `state.from` (the same convention `AuthProvider` uses for
 *   its 401 handler) so login can return the user there.
 * - `authenticated` → render children, or `<Outlet />` when used as a layout
 *   route.
 */
export function ProtectedRoute({ children }: { children?: ReactNode }) {
  const { status } = useAuth()
  const location = useLocation()

  if (status === 'loading') {
    return (
      <p role="status" aria-live="polite">
        Checking your session…
      </p>
    )
  }

  if (status === 'unauthenticated') {
    return (
      <Navigate
        to={LOGIN_ROUTE}
        replace
        state={{ from: location.pathname + location.search }}
      />
    )
  }

  return children ? <>{children}</> : <Outlet />
}
