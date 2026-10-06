import { PipelineHealthWidget } from '../components/PipelineHealthWidget'
import './dashboard.css'

/**
 * Authenticated landing screen; the rest of the real content lands with the
 * dashboard work.
 *
 * The pipeline-health widget is here rather than on the applications list
 * because it reports on things that happened while nobody was watching — the
 * first screen after sign-in is where that belongs (Requirement 11.2).
 */
export function DashboardPage() {
  return (
    <>
      <h1 className="page__title">Dashboard</h1>
      <PipelineHealthWidget />
      <p className="page__intro">
        Your pipeline summary will appear here — recent job matches, tailored
        resumes, and application activity.
      </p>
    </>
  )
}
