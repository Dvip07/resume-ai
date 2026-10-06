import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

import {
  applicationKeys,
  applicationTitle,
  fetchApplications,
  formatDateTime,
  isPendingStage,
  pollIntervalForApplicationList,
  responseStatusLabel,
  starRating,
  type ApplicationSummary,
} from '../api/applications'
import { summaryMessage } from '../api/validationErrors'
import { PipelineStageBadge } from '../components/PipelineStageBadge'
import { applicationDetailPath } from './paths'
import './resumes.css'
import './applications.css'

/**
 * Applications dashboard (Requirements 10.1, 10.3).
 *
 * One row per application, each carrying its listing's pipeline stage and the
 * current star rating. Stages advance on a queue worker's schedule, so the list
 * re-fetches itself while any row is mid-pipeline and stops once every row has
 * settled on a terminal stage — the same "never a silent processing state" rule
 * the resumes screen follows.
 *
 * Rows needing a human are pulled out into a banner above the list and marked
 * in place, so a `needs_review` application can't be missed by scrolling past
 * it (Requirement 10.3).
 */
export function ApplicationsPage() {
  const [page, setPage] = useState(1)

  const listQuery = useQuery({
    queryKey: applicationKeys.list(page),
    queryFn: () => fetchApplications(page),
    refetchInterval: (query) => pollIntervalForApplicationList(query.state.data),
    refetchIntervalInBackground: false,
  })

  const applications = listQuery.data?.data ?? []
  const lastPage = listQuery.data?.last_page ?? 1
  const total = listQuery.data?.total ?? 0

  const needsReview = applications.filter((application) => application.needs_review)
  const inFlight = applications.filter((application) =>
    isPendingStage(application.job_listing?.pipeline_stage ?? null),
  )

  // A page can vanish under us as rows move around.
  useEffect(() => {
    if (page > 1 && page > lastPage) setPage(lastPage)
  }, [page, lastPage])

  return (
    <>
      <h1 className="page__title">Applications</h1>
      <p className="resumes__intro">
        Every job the pipeline has picked up for you, with where it got to and
        how it rated. This list updates on its own while work is still running.
      </p>

      <p className="visually-hidden" role="status" aria-live="polite">
        {inFlight.length > 0
          ? `${inFlight.length} application${inFlight.length === 1 ? '' : 's'} still moving through the pipeline.`
          : applications.length > 0
            ? 'All applications on this page have settled.'
            : ''}
      </p>

      {needsReview.length > 0 ? (
        <ReviewBanner applications={needsReview} />
      ) : null}

      <h2 className="section-title">
        Tracked applications{total > 0 ? ` (${total})` : null}
      </h2>

      {listQuery.isPending ? (
        <p className="state">Loading your applications…</p>
      ) : listQuery.isError ? (
        <div className="state state--error" role="alert">
          <p style={{ margin: 0 }}>{summaryMessage(listQuery.error, false)}</p>
          <p style={{ margin: '0.5rem 0 0' }}>
            <button
              type="button"
              className="btn btn--secondary"
              onClick={() => void listQuery.refetch()}
            >
              Try again
            </button>
          </p>
        </div>
      ) : applications.length === 0 ? (
        <p className="state">
          Nothing here yet. Once a discovered job is scored and tailored, the
          application shows up on this list.
        </p>
      ) : (
        <>
          <ul className="resume-list">
            {applications.map((application) => (
              <ApplicationRow key={application.id} application={application} />
            ))}
          </ul>

          {lastPage > 1 ? (
            <nav className="pager" aria-label="Application pages">
              <button
                type="button"
                className="btn btn--secondary"
                onClick={() => setPage((current) => Math.max(1, current - 1))}
                disabled={page <= 1}
              >
                Previous
              </button>
              <span className="pager__status">
                Page {page} of {lastPage}
              </span>
              <button
                type="button"
                className="btn btn--secondary"
                onClick={() => setPage((current) => Math.min(lastPage, current + 1))}
                disabled={page >= lastPage}
              >
                Next
              </button>
            </nav>
          ) : null}
        </>
      )}
    </>
  )
}

/**
 * The call-to-action for everything paused on this page (Requirement 10.3).
 *
 * `role="alert"`, not a quiet highlight: these are the only rows where the
 * pipeline is waiting on the user, and the whole point of the stage is that
 * nothing moves until someone acts.
 */
function ReviewBanner({ applications }: { applications: ApplicationSummary[] }) {
  return (
    <section className="review-banner" role="alert" aria-labelledby="review-banner-heading">
      <h2 className="review-banner__title" id="review-banner-heading">
        {applications.length === 1
          ? '1 application needs you'
          : `${applications.length} applications need you`}
      </h2>
      <ul className="review-banner__list">
        {applications.map((application) => (
          <li key={application.id}>
            <strong>{application.review?.headline ?? 'Paused for review'}</strong>{' '}
            — {applicationTitle(application)}{' '}
            <Link to={applicationDetailPath(application.id)}>Review and continue</Link>
          </li>
        ))}
      </ul>
    </section>
  )
}

/** One row: title, stage badge, star rating, and reported employer response. */
function ApplicationRow({ application }: { application: ApplicationSummary }) {
  const listing = application.job_listing
  const rating = starRating(application.score?.stars)
  const title = applicationTitle(application)

  return (
    <li
      className={
        application.needs_review
          ? 'resume-list__item resume-list__item--review'
          : 'resume-list__item'
      }
    >
      <div className="resume-list__main">
        <p className="resume-list__name">
          <Link to={applicationDetailPath(application.id)}>{title}</Link>
        </p>

        <div className="resume-list__meta">
          <PipelineStageBadge stage={listing?.pipeline_stage} />
          {rating ? (
            <span className="stars">
              <span aria-hidden="true">{rating.glyphs}</span>
              <span className="visually-hidden">{rating.label}</span>
            </span>
          ) : (
            <span>Not rated yet</span>
          )}
          {listing?.location ? <span>{listing.location}</span> : null}
          <span>Response: {responseStatusLabel(application.response_status)}</span>
        </div>

        {application.needs_review && application.review ? (
          <p className="resume-list__reason">
            <strong>{application.review.headline}:</strong>{' '}
            {application.review.detail}
          </p>
        ) : null}

        <p className="resume-list__meta">
          <span>
            {application.applied_at
              ? `Applied ${formatDateTime(application.applied_at)}`
              : `Created ${formatDateTime(application.created_at)}`}
          </span>
        </p>
      </div>

      <div className="resume-list__actions">
        <Link
          to={applicationDetailPath(application.id)}
          className={application.needs_review ? 'btn' : 'btn btn--secondary'}
        >
          {application.needs_review ? 'Review' : 'View details'}
          <span className="visually-hidden"> — {title}</span>
        </Link>
      </div>
    </li>
  )
}
