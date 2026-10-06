import { Link } from 'react-router-dom'

import '../layout/layout.css'
import { DASHBOARD_ROUTE } from './paths'

/**
 * Catch-all for unmatched paths. It sits outside `AppLayout` (an unknown URL
 * can be hit while signed out), so it carries its own `<main>` landmark.
 */
export function NotFoundPage() {
  return (
    <main className="app__content">
      <h1 className="page__title">Page not found</h1>
      <p className="page__intro">
        That URL doesn&rsquo;t match anything.{' '}
        <Link to={DASHBOARD_ROUTE}>Go to the dashboard</Link>.
      </p>
    </main>
  )
}
