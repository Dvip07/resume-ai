import type { ReactNode } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'

import { intendedDestination } from './authContext'
import { useAuth } from './useAuth'

/**
 * Inverse of `ProtectedRoute`: wraps the guest-only auth screens (/login,
 * /register) and pushes an already-authenticated user on to wherever they
 * were originally headed (`state.from`), or the default landing route.
 */
export function GuestRoute({ children }: { children?: ReactNode }) {
  const { status } = useAuth()
  const location = useLocation()

  if (status === 'loading') {
    return (
      <p role="status" aria-live="polite">
        Checking your session…
      </p>
    )
  }

  if (status === 'authenticated') {
    return <Navigate to={intendedDestination(location.state)} replace />
  }

  return children ? <>{children}</> : <Outlet />
}
