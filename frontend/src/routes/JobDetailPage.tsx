import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import {
  formatDateTime,
  readFitAnalysis,
  stageDescription,
  starRating,
} from '../api/applications'
import {
  applyToJob,
  fetchJob,
  jobKeys,
  jobSource,
  jobTitle,
  pollIntervalForJob,
  readSkills,
  recommendedActionLabel,
  rescoreJob,
  salaryRange,
  tailorJob,
  type JobListing,
  type QueuedResponse,
} from '../api/jobs'
import { summaryMessage } from '../api/validationErrors'
import { PipelineStageBadge } from '../components/PipelineStageBadge'
import { JOBS_ROUTE } from './paths'
import './resumes.css'
import './applications.css'
import './jobs.css'

/**
 * Single job view (Requirements 10.2, 11.1).
 *
 * Shows the stored description, the user's current score with its rationale and
 * fit analysis, and the three manual pipeline triggers the API exposes. Each
 * trigger is queue-and-202: the button reports that the work was *queued*, not
 * that it finished, and the screen then polls until the stage settles. Nothing
 * here claims an outcome the backend has not reached.
 */
export function JobDetailPage() {
  const { jobId } = useParams()

  const jobQuery = useQuery({
    queryKey: jobKeys.detail(jobId ?? ''),
    queryFn: () => fetchJob(jobId as string),
    enabled: Boolean(jobId),
    refetchInterval: (query) => pollIntervalForJob(query.state.data),
    refetchIntervalInBackground: false,
  })

  if (!jobId) {
    return <p className="state state--error">No job was specified.</p>
  }

  if (jobQuery.isPending) {
    return <p className="state">Loading job…</p>
  }

  if (jobQuery.isError) {
    return (
      <div className="state state--error" role="alert">
        <p style={{ margin: 0 }}>{summaryMessage(jobQuery.error, false)}</p>
        <p style={{ margin: '0.5rem 0 0' }}>
          <Link to={JOBS_ROUTE}>Back to jobs</Link>
        </p>
      </div>
    )
  }

  return <JobDetail listing={jobQuery.data} />
}

function JobDetail({ listing }: { listing: JobListing }) {
  const title = jobTitle(listing)
  const rating = starRating(listing.score?.stars)
  const source = jobSource(listing)
  const salary = salaryRange(listing)
  const skills = readSkills(listing.parsed_skills)
  const fitSections = readFitAnalysis(listing.score?.fit_analysis)
  const action = recommendedActionLabel(listing.score?.recommended_action)

  return (
    <>
      <p>
        <Link to={JOBS_ROUTE} className="resume-detail__back">
          ← Back to jobs
        </Link>
      </p>

      <h1 className="page__title">{title}</h1>

      <div className="resume-list__meta">
        <PipelineStageBadge stage={listing.pipeline_stage} />
        {rating ? (
          <span className="stars">
            <span aria-hidden="true">{rating.glyphs}</span>
            <span className="visually-hidden">{rating.label}</span>
          </span>
        ) : (
          <span>Not rated yet</span>
        )}
        {listing.location ? <span>{listing.location}</span> : null}
        {source ? <span>Source: {source}</span> : null}
        <span>{salary ?? 'Salary not stated'}</span>
        <span>
          {listing.posted_at ? `Posted ${formatDateTime(listing.posted_at)}` : 'Posting date unknown'}
        </span>
      </div>

      <p className="job-detail__stage-note">{stageDescription(listing.pipeline_stage)}</p>

      <JobActions listing={listing} />

      {listing.score ? (
        <section className="job-detail__section">
          <h2 className="section-title">Fit</h2>
          <div className="resume-list__meta">
            {action ? <span>Recommended: {action}</span> : null}
            {listing.score.attempt_number != null ? (
              <span>Score attempt {listing.score.attempt_number}</span>
            ) : null}
            {listing.score.created_at ? (
              <span>Scored {formatDateTime(listing.score.created_at)}</span>
            ) : null}
          </div>

          {listing.score.rationale ? (
            <p className="job-detail__text">{listing.score.rationale}</p>
          ) : (
            <p className="state">No rationale was recorded for this score.</p>
          )}

          {fitSections.map((section) => (
            <div key={section.label}>
              <h3 className="job-detail__subtitle">{section.label}</h3>
              <ul className="job-detail__list">
                {section.items.map((item, index) => (
                  <li key={`${section.label}-${index}`}>{item}</li>
                ))}
              </ul>
            </div>
          ))}
        </section>
      ) : (
        <section className="job-detail__section">
          <h2 className="section-title">Fit</h2>
          <p className="state">
            This job has not been scored against your profile yet.
          </p>
        </section>
      )}

      {skills.length > 0 ? (
        <section className="job-detail__section">
          <h2 className="section-title">Skills in this posting</h2>
          <ul className="job-detail__chips">
            {skills.map((skill) => (
              <li key={skill}>{skill}</li>
            ))}
          </ul>
        </section>
      ) : null}

      <section className="job-detail__section">
        <h2 className="section-title">Job description</h2>
        {listing.description?.trim() ? (
          // Whitespace is preserved rather than parsed: the stored description
          // is provider text of unknown format, so rendering it as markup would
          // be both unsafe and wrong.
          <p className="job-detail__text job-detail__text--preserve">
            {listing.description}
          </p>
        ) : (
          <p className="state">
            No description stored yet. The pipeline fetches the full text in the
            background when a posting arrives with only a summary.
          </p>
        )}

        {listing.application_url ? (
          <p>
            <a
              className="btn btn--secondary"
              href={listing.application_url}
              target="_blank"
              rel="noreferrer noopener"
            >
              Open the original posting
              <span className="visually-hidden"> — {title} (opens in a new tab)</span>
            </a>
          </p>
        ) : null}
      </section>
    </>
  )
}

/**
 * The three manual triggers (`rescore`, `tailor`, `apply`).
 *
 * One shared status line, because only one of these should be in flight at a
 * time and three separate messages would be noise. The message is deliberately
 * about queueing — the result shows up as a stage change, which polling picks
 * up.
 */
function JobActions({ listing }: { listing: JobListing }) {
  const queryClient = useQueryClient()
  const [queued, setQueued] = useState<string | null>(null)
  const [failed, setFailed] = useState<string | null>(null)

  function runner(mutationFn: () => Promise<QueuedResponse>, fallback: string) {
    return {
      mutationFn,
      onMutate: () => {
        setQueued(null)
        setFailed(null)
      },
      onSuccess: (response: QueuedResponse) => {
        setQueued(response.message ?? fallback)
        void queryClient.invalidateQueries({ queryKey: jobKeys.all })
      },
      onError: (error: unknown) => {
        setFailed(summaryMessage(error, false) ?? fallback)
      },
    }
  }

  const rescore = useMutation(
    runner(() => rescoreJob(listing.id), 'Re-scoring has been queued.'),
  )
  const tailor = useMutation(
    runner(() => tailorJob(listing.id), 'Tailoring has been queued.'),
  )
  const apply = useMutation(
    runner(() => applyToJob(listing.id), 'The application has been queued.'),
  )

  const busy = rescore.isPending || tailor.isPending || apply.isPending

  return (
    <section className="job-actions" aria-labelledby="job-actions-heading">
      <h2 className="section-title" id="job-actions-heading">
        Actions
      </h2>

      <div className="job-actions__row">
        <button
          type="button"
          className="btn btn--secondary"
          onClick={() => rescore.mutate()}
          disabled={busy}
        >
          {rescore.isPending ? 'Queueing…' : 'Re-score'}
        </button>
        <button
          type="button"
          className="btn btn--secondary"
          onClick={() => tailor.mutate()}
          disabled={busy}
        >
          {tailor.isPending ? 'Queueing…' : 'Tailor documents'}
        </button>
        <button
          type="button"
          className="btn"
          onClick={() => apply.mutate()}
          disabled={busy}
        >
          {apply.isPending ? 'Queueing…' : 'Apply now'}
        </button>
      </div>

      <p className="job-actions__hint">
        Each action runs in the background. The stage above updates on its own
        when it completes — or moves to “Needs review” if it needs you.
      </p>

      <p className="state state--quiet" role="status" aria-live="polite">
        {queued ?? ''}
      </p>

      {failed ? (
        <p className="state state--error" role="alert">
          {failed}
        </p>
      ) : null}
    </section>
  )
}
