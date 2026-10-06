import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

import { PIPELINE_STAGES, stageLabel, starRating } from '../api/applications'
import {
  discoverJobs,
  fetchJobs,
  isJobPending,
  jobKeys,
  jobSource,
  jobTitle,
  pollIntervalForJobList,
  salaryRange,
  type JobFilters,
  type JobListing,
} from '../api/jobs'
import { summaryMessage } from '../api/validationErrors'
import { PipelineStageBadge } from '../components/PipelineStageBadge'
import { jobDetailPath } from './paths'
import './resumes.css'
import './applications.css'
import './jobs.css'

/**
 * Discovered jobs (Requirements 10.1, 11.1).
 *
 * The list is a pure read: discovery itself is a queued run the user triggers
 * with the button, which returns 202 rather than results. So the screen polls
 * while any row is still mid-pipeline and says plainly that a new run takes a
 * while to show up — nothing here pretends the queue has finished.
 *
 * Filters mirror the API's (`pipeline_stage`, `source`, minimum `rating`) and
 * live in the query key, so each combination caches separately and switching
 * back to a previous filter is instant.
 */
export function JobsPage() {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [filters, setFilters] = useState<JobFilters>({})
  const [queued, setQueued] = useState<string | null>(null)

  const listQuery = useQuery({
    queryKey: jobKeys.list(page, filters),
    queryFn: () => fetchJobs(page, filters),
    refetchInterval: (query) => pollIntervalForJobList(query.state.data),
    refetchIntervalInBackground: false,
  })

  const discover = useMutation({
    mutationFn: () => discoverJobs(),
    onSuccess: (response) => {
      setQueued(
        response.message ??
          'Job discovery has been queued. New matches appear here as they are found.',
      )
      void queryClient.invalidateQueries({ queryKey: jobKeys.all })
    },
  })

  const listings = listQuery.data?.data ?? []
  const lastPage = listQuery.data?.last_page ?? 1
  const total = listQuery.data?.total ?? 0
  const inFlight = listings.filter((listing) => isJobPending(listing.pipeline_stage))

  // A filter change can leave us past the end of the new result set.
  useEffect(() => {
    if (page > 1 && page > lastPage) setPage(lastPage)
  }, [page, lastPage])

  function updateFilter(patch: JobFilters) {
    setFilters((current) => ({ ...current, ...patch }))
    setPage(1)
  }

  const sources = Array.from(
    new Set(
      listings
        .map((listing) => listing.source_key ?? listing.api_source)
        .filter((source): source is string => Boolean(source)),
    ),
  ).sort()

  return (
    <>
      <h1 className="page__title">Jobs</h1>
      <p className="resumes__intro">
        Everything the pipeline has discovered for you, with its stage and how it
        rated against your profile. This list updates on its own while work is
        still running.
      </p>

      <section className="job-actions">
        <button
          type="button"
          className="btn"
          onClick={() => discover.mutate()}
          disabled={discover.isPending}
        >
          {discover.isPending ? 'Queueing…' : 'Find new jobs'}
        </button>
        <p className="job-actions__hint">
          Searches your suggested roles across the configured sources. The run
          happens in the background, so results arrive over the next few minutes.
        </p>
      </section>

      {discover.isError ? (
        <div className="state state--error" role="alert">
          {summaryMessage(discover.error, false)}
        </div>
      ) : null}

      <p className="state state--quiet" role="status" aria-live="polite">
        {queued ?? ''}
      </p>

      <fieldset className="job-filters">
        <legend className="job-filters__legend">Filter</legend>

        <label className="job-filters__field">
          <span>Stage</span>
          <select
            value={filters.pipeline_stage ?? ''}
            onChange={(event) =>
              updateFilter({ pipeline_stage: event.target.value || undefined })
            }
          >
            <option value="">Any stage</option>
            {PIPELINE_STAGES.map((stage) => (
              <option key={stage} value={stage}>
                {stageLabel(stage)}
              </option>
            ))}
          </select>
        </label>

        <label className="job-filters__field">
          <span>Source</span>
          <select
            value={filters.source ?? ''}
            onChange={(event) => updateFilter({ source: event.target.value || undefined })}
          >
            <option value="">Any source</option>
            {/* Options come from the rows on screen plus whatever is already
                selected, so an active filter never disappears from its own
                dropdown when it narrows the page to nothing. */}
            {Array.from(new Set([...sources, filters.source].filter(Boolean))).map(
              (source) => (
                <option key={source as string} value={source as string}>
                  {(source as string).replace(/[_-]/g, ' ')}
                </option>
              ),
            )}
          </select>
        </label>

        <label className="job-filters__field">
          <span>Minimum rating</span>
          <select
            value={filters.rating ?? ''}
            onChange={(event) =>
              updateFilter({
                rating: event.target.value ? Number(event.target.value) : undefined,
              })
            }
          >
            <option value="">Any rating</option>
            {[5, 4, 3, 2, 1].map((stars) => (
              <option key={stars} value={stars}>
                {stars} star{stars === 1 ? '' : 's'} and up
              </option>
            ))}
          </select>
        </label>
      </fieldset>

      <p className="visually-hidden" role="status" aria-live="polite">
        {inFlight.length > 0
          ? `${inFlight.length} job${inFlight.length === 1 ? '' : 's'} still moving through the pipeline.`
          : listings.length > 0
            ? 'All jobs on this page have settled.'
            : ''}
      </p>

      <h2 className="section-title">
        Discovered jobs{total > 0 ? ` (${total})` : null}
      </h2>

      {listQuery.isPending ? (
        <p className="state">Loading jobs…</p>
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
      ) : listings.length === 0 ? (
        <p className="state">
          {Object.values(filters).some(Boolean)
            ? 'No jobs match these filters.'
            : 'Nothing discovered yet. Run a search, or wait for the next scheduled one.'}
        </p>
      ) : (
        <>
          <ul className="resume-list">
            {listings.map((listing) => (
              <JobRow key={listing.id} listing={listing} />
            ))}
          </ul>

          {lastPage > 1 ? (
            <nav className="pager" aria-label="Job pages">
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

/** One row: title, stage, rating, source, location, and salary. */
function JobRow({ listing }: { listing: JobListing }) {
  const rating = starRating(listing.score?.stars)
  const title = jobTitle(listing)
  const source = jobSource(listing)
  const salary = salaryRange(listing)

  return (
    <li className="resume-list__item">
      <div className="resume-list__main">
        <p className="resume-list__name">
          <Link to={jobDetailPath(listing.id)}>{title}</Link>
        </p>

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
        </div>
      </div>

      <div className="resume-list__actions">
        <Link to={jobDetailPath(listing.id)} className="btn btn--secondary">
          View details
          <span className="visually-hidden"> — {title}</span>
        </Link>
      </div>
    </li>
  )
}
