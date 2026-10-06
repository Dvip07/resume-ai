import { useCallback, useEffect, useMemo, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocation, useNavigate } from 'react-router-dom'

import { ApiError, api, setUnauthorizedHandler } from '../api/client'
import { clearBearerToken } from '../api/tokenStorage'
import {
  AuthContext,
  LOGIN_ROUTE,
  meQueryKey,
  type AuthContextValue,
  type AuthStatus,
  type AuthUser,
  type LoginCredentials,
  type RegisterCredentials,
} from './authContext'

/**
 * Auth state for the app.
 *
 * The Sanctum token is held in a backend-set httpOnly cookie (open decision
 * #6), so the frontend cannot inspect it. Authenticated/unauthenticated state
 * is therefore derived from `GET /api/me`:
 * - 200 → authenticated
 * - 401 → unauthenticated; the API client's unauthorized handler clears cached
 *   auth state and redirects to /login (Requirement 1.6)
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()

  const {
    data: user,
    isPending,
    isError,
    error,
  } = useQuery<AuthUser | null>({
    queryKey: meQueryKey,
    queryFn: async () => {
      try {
        return await api.get<AuthUser>('/me')
      } catch (probeError) {
        // A 401 here is the expected "no session" answer, not a failure.
        if (probeError instanceof ApiError && probeError.status === 401) {
          return null
        }
        throw probeError
      }
    },
    retry: false,
    staleTime: 60_000,
  })

  // A 401 from anywhere in the app (not just /me) drops auth state and sends
  // the user to login. Registered here so the redirect is router-driven.
  useEffect(() => {
    setUnauthorizedHandler(() => {
      clearBearerToken()
      // Set (don't remove) the cached value: removing it would trigger an
      // immediate refetch and another 401.
      queryClient.setQueryData(meQueryKey, null)

      if (location.pathname !== LOGIN_ROUTE) {
        navigate(LOGIN_ROUTE, {
          replace: true,
          state: { from: location.pathname + location.search },
        })
      }
    })

    return () => setUnauthorizedHandler(null)
  }, [location.pathname, location.search, navigate, queryClient])

  const login = useCallback(
    async (credentials: LoginCredentials) => {
      const { user: loggedIn } = await api.post<{ user: AuthUser; token: string }>(
        '/auth/login',
        credentials,
        { skipAuth: true },
      )
      // The response also set the httpOnly cookie; nothing to store client-side.
      queryClient.setQueryData(meQueryKey, loggedIn)
      return loggedIn
    },
    [queryClient],
  )

  const register = useCallback(
    async (credentials: RegisterCredentials) => {
      const { user: registered } = await api.post<{ user: AuthUser; token: string }>(
        '/auth/register',
        credentials,
        { skipAuth: true },
      )
      queryClient.setQueryData(meQueryKey, registered)
      return registered
    },
    [queryClient],
  )

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } catch (logoutError) {
      // An expired session already means logged out; anything else is
      // surfaced but must not leave stale auth state behind.
      if (!(logoutError instanceof ApiError) || logoutError.status !== 401) {
        throw logoutError
      }
    } finally {
      clearBearerToken()
      queryClient.setQueryData(meQueryKey, null)
      queryClient.clear()
      navigate(LOGIN_ROUTE, { replace: true })
    }
  }, [navigate, queryClient])

  const refresh = useCallback(async () => {
    await queryClient.invalidateQueries({ queryKey: meQueryKey })
  }, [queryClient])

  const status: AuthStatus = useMemo(() => {
    if (isPending) return 'loading'
    if (isError || !user) {
      // A non-401 failure (backend down, CORS) is still "not authenticated",
      // but the error itself stays visible in the query cache for callers.
      void error
      return 'unauthenticated'
    }
    return 'authenticated'
  }, [error, isError, isPending, user])

  const value: AuthContextValue = useMemo(
    () => ({
      status,
      user: user ?? null,
      login,
      register,
      logout,
      refresh,
    }),
    [login, logout, refresh, register, status, user],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
