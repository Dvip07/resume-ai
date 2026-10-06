import { QueryClient } from '@tanstack/react-query'

import { ApiError } from './client'

/**
 * Shared TanStack Query client. 401s are handled by the API client (token
 * cleared + unauthorized handler), so retrying them is pointless.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (failureCount, error) => {
        if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
          return false
        }
        return failureCount < 2
      },
      staleTime: 30_000,
      refetchOnWindowFocus: false,
    },
    mutations: {
      retry: false,
    },
  },
})
