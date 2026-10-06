/**
 * HTTP client for the decoupled Laravel backend.
 *
 * - Base URL comes from `VITE_API_BASE_URL` (Requirement 1.3, 1B.2) — never
 *   hard-coded here.
 * - Credentials: the Sanctum token lives in a backend-set httpOnly cookie
 *   (open decision #6), so every request is sent with
 *   `credentials: 'include'` plus the `X-Requested-With: XMLHttpRequest`
 *   header the backend requires before it will honour that cookie (CSRF
 *   mitigation). An explicit `Authorization: Bearer <token>` is still sent
 *   when an in-memory token has been set, for non-cookie flows
 *   (Requirement 1.5).
 * - A 401 clears any in-memory token and notifies the registered unauthorized
 *   handler, which the auth provider uses to reset auth state and redirect to
 *   /login (Requirement 1.6).
 */

import {
  clearBearerToken,
  CREDENTIALS_MODE,
  getBearerToken,
  REQUESTED_WITH_HEADER,
} from './tokenStorage'

const rawBaseUrl = import.meta.env.VITE_API_BASE_URL

if (!rawBaseUrl && import.meta.env.DEV) {
  console.warn(
    '[api] VITE_API_BASE_URL is not set. Set it in frontend/.env.local ' +
      '(e.g. http://localhost:8000/api).',
  )
}

/** Base API URL without a trailing slash. */
export const API_BASE_URL = (rawBaseUrl ?? '').replace(/\/+$/, '')

export class ApiError extends Error {
  readonly status: number
  readonly body: unknown

  constructor(status: number, message: string, body: unknown) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }
}

type UnauthorizedHandler = () => void

let unauthorizedHandler: UnauthorizedHandler | null = null

/** Register what should happen after a 401 (e.g. redirect to /login). */
export function setUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
  unauthorizedHandler = handler
}

export interface RequestOptions extends Omit<RequestInit, 'body'> {
  /** Plain object (JSON-encoded), FormData, or a pre-built body. */
  body?: unknown
  /** Query string parameters; `undefined`/`null` values are dropped. */
  query?: Record<string, string | number | boolean | null | undefined>
  /** Skip the in-memory bearer header (login/register calls). */
  skipAuth?: boolean
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`
  const url = `${API_BASE_URL}${normalizedPath}`

  if (!query) return url

  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null) continue
    params.append(key, String(value))
  }

  const queryString = params.toString()
  return queryString ? `${url}?${queryString}` : url
}

function isBodyInit(body: unknown): body is BodyInit {
  return (
    typeof body === 'string' ||
    body instanceof FormData ||
    body instanceof Blob ||
    body instanceof ArrayBuffer ||
    body instanceof URLSearchParams
  )
}

async function parseBody(response: Response): Promise<unknown> {
  if (response.status === 204) return null

  const contentType = response.headers.get('content-type') ?? ''
  if (contentType.includes('application/json')) {
    try {
      return await response.json()
    } catch {
      return null
    }
  }

  const text = await response.text()
  return text.length > 0 ? text : null
}

function errorMessage(status: number, body: unknown): string {
  if (body && typeof body === 'object' && 'message' in body) {
    const message = (body as { message?: unknown }).message
    if (typeof message === 'string' && message.length > 0) return message
  }
  if (typeof body === 'string' && body.length > 0) return body
  return `Request failed with status ${status}`
}

export async function request<T = unknown>(
  path: string,
  { body, query, skipAuth = false, headers, ...init }: RequestOptions = {},
): Promise<T> {
  const requestHeaders = new Headers(headers)
  requestHeaders.set('Accept', 'application/json')
  // Required by the backend before it will read the httpOnly auth cookie.
  requestHeaders.set(REQUESTED_WITH_HEADER.name, REQUESTED_WITH_HEADER.value)

  if (!skipAuth) {
    const token = getBearerToken()
    if (token) {
      requestHeaders.set('Authorization', `Bearer ${token}`)
    }
  }

  let requestBody: BodyInit | undefined
  if (body !== undefined && body !== null) {
    if (isBodyInit(body)) {
      requestBody = body
    } else {
      requestBody = JSON.stringify(body)
      if (!requestHeaders.has('Content-Type')) {
        requestHeaders.set('Content-Type', 'application/json')
      }
    }
  }

  const response = await fetch(buildUrl(path, query), {
    credentials: CREDENTIALS_MODE,
    ...init,
    headers: requestHeaders,
    body: requestBody,
  })

  const payload = await parseBody(response)

  if (response.status === 401) {
    clearBearerToken()
    unauthorizedHandler?.()
  }

  if (!response.ok) {
    throw new ApiError(response.status, errorMessage(response.status, payload), payload)
  }

  return payload as T
}

export const api = {
  get: <T = unknown>(path: string, options?: RequestOptions) =>
    request<T>(path, { ...options, method: 'GET' }),
  post: <T = unknown>(path: string, body?: unknown, options?: RequestOptions) =>
    request<T>(path, { ...options, method: 'POST', body }),
  patch: <T = unknown>(path: string, body?: unknown, options?: RequestOptions) =>
    request<T>(path, { ...options, method: 'PATCH', body }),
  put: <T = unknown>(path: string, body?: unknown, options?: RequestOptions) =>
    request<T>(path, { ...options, method: 'PUT', body }),
  delete: <T = unknown>(path: string, options?: RequestOptions) =>
    request<T>(path, { ...options, method: 'DELETE' }),
}
