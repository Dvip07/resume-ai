import { useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'

import { useAuth } from '../auth/useAuth'
import { DASHBOARD_ROUTE } from '../routes/paths'
import { NAV_ITEMS } from './navItems'
import './layout.css'

/** Id of the content region the skip link jumps to. */
const CONTENT_ID = 'app-content'

/**
 * Shell for every authenticated screen (Requirement 1.4): app header with the
 * signed-in user and a sign-out control, primary nav, and the routed content
 * region. Written as the frontend's own component tree — no markup is carried
 * over from the backend's Blade layouts.
 *
 * Used as the element of the layout route nested inside `ProtectedRoute`, so
 * it only ever renders for an authenticated session.
 *
 * Accessibility: `banner`/`navigation`/`main` landmarks come from the semantic
 * elements, a skip link precedes the header, and `NavLink` marks the matched
 * item with `aria-current="page"`.
 */
export function AppLayout() {
  const { user, logout } = useAuth()
  const [signOutError, setSignOutError] = useState<string | null>(null)

  async function handleSignOut() {
    setSignOutError(null)

    try {
      await logout()
      // No navigation needed: clearing auth state makes ProtectedRoute
      // redirect to /login on the next render.
    } catch {
      setSignOutError('Sign out failed. Please try again.')
    }
  }

  return (
    <div className="app">
      <a className="app__skip-link" href={`#${CONTENT_ID}`}>
        Skip to main content
      </a>

      <header className="app__header">
        <NavLink to={DASHBOARD_ROUTE} className="app__brand" end>
          resume-ai
        </NavLink>

        <div className="app__account">
          {user ? (
            <span className="app__user">
              Signed in as <strong>{user.name || user.email}</strong>
            </span>
          ) : null}

          <button type="button" className="app__signout" onClick={() => void handleSignOut()}>
            Sign out
          </button>
        </div>
      </header>

      <nav className="app__nav" aria-label="Primary">
        <ul className="app__nav-list">
          {NAV_ITEMS.map((item) => (
            <li key={item.to}>
              <NavLink
                to={item.to}
                end={item.end}
                className={({ isActive }) =>
                  isActive ? 'app__nav-link app__nav-link--active' : 'app__nav-link'
                }
              >
                {item.label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>

      {signOutError ? (
        <p className="app__error" role="alert">
          {signOutError}
        </p>
      ) : null}

      <main className="app__content" id={CONTENT_ID}>
        <Outlet />
      </main>
    </div>
  )
}
