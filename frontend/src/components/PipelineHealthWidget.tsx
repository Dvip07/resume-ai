import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { formatDateTime } from '../api/applications'
import {
  fetchPipelineHealth,
  hasPipelineTrouble,
  pipelineHealthKeys,
} from '../api/pipelineHealth'
import { APPLICATIONS_ROUTE } from '../routes/paths'

/**
 * Dashboard status widget for pipeline failures (Requirement 11.2).
 *
 * The pipeline runs on a queue worker, so a stage that gives up does it where
 * nobody is looking. This is the surface that makes that visible.
 *
 * Healthy accounts get one calm line, not a warning box with zeros in it — a
 * dashboard that shouts "0 failures" every day teaches people to ignore it.
 * The alarming treatment is reserved for the case where there is actually
 * something to do.
 *
 * The queue number is labelled as platform-wide because that is what it is:
 * `failed_jobs` rows carry no user attribution, and presenting them as the
 * user's own failures would be a lie the backend can't back up.
 *
 * `needs_review` rows are the actionable half, so the widget links through to
 * the applications list, which already pulls those out into a banner of its
 * own. The list takes no stage filter in the URL today, so this is a plain link
 * rather than a filtered one.
 */
export function PipelineHealthWidget() {
  const healthQuery = useQuery({
    queryKey: pipelineHealthKeys.detail,
    queryFn: fetchPipelineHealth,
    // Failures appear between requests; a slow refresh keeps the widget honest
    // without polling hard for something that is usually zero.
    refetchInterval: 60_000,
    refetchIntervalInBackground: false,
  })

  const health = healthQuery.data

  if (healthQuery.isPending || healthQuery.isError || !health) {
    // A status widget that can't load its status says nothing. Its failure is
    // not itself news, and the rest of the dashboard still works.
    return null
  }

  if (!hasPipelineTrouble(health)) {
    return (
      <section className="health health--ok" aria-labelledby="health-heading">
        <h2 className="health__heading" id="health-heading">
          Pipeline health
        </h2>
        <p className="health__all-clear">
          Nothing needs your attention. Everything the pipeline has picked up is
          either still moving or finished cleanly.
        </p>
      </section>
    )
  }

  const { stages, queue, latest_failure: latest } = health

  return (
    <section className="health health--attention" aria-labelledby="health-heading">
      <h2 className="health__heading" id="health-heading">
        Pipeline health
      </h2>

      <dl className="health__counts">
        {stages.needs_review > 0 && (
          <div className="health__count">
            <dt>Waiting on you</dt>
            <dd>{stages.needs_review}</dd>
          </div>
        )}
        {stages.failed > 0 && (
          <div className="health__count">
            <dt>Failed</dt>
            <dd>{stages.failed}</dd>
          </div>
        )}
        {queue.failed_jobs > 0 && (
          <div className="health__count health__count--operational">
            <dt>
              Background jobs failed
              <span className="health__scope"> (platform-wide)</span>
            </dt>
            <dd>{queue.failed_jobs}</dd>
          </div>
        )}
      </dl>

      {latest && (
        <p className="health__latest">
          Most recent:{' '}
          <strong>{latest.job_title ?? 'A job'}</strong>
          {latest.company ? ` at ${latest.company}` : ''}
          {latest.occurred_at ? ` — ${formatDateTime(latest.occurred_at)}` : ''}
          {latest.reason ? `. ${latest.reason}` : '.'}
        </p>
      )}

      {stages.total > 0 && (
        <Link className="health__link" to={APPLICATIONS_ROUTE}>
          Review these applications
        </Link>
      )}
    </section>
  )
}
