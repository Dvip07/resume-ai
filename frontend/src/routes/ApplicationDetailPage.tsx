import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'

import { ApiError } from '../api/client'
import {
  applicationKeys,
  applicationTitle,
  documentTypeLabel,
  fetchApplication,
  formatDateTime,
  hasAutomationLog,
  pollIntervalForApplication,
  readAutomationLog,
  readFitAnalysis,
  responseStatusLabel,
  RESPONSE_STATUSES,
  stageDescription,
  starRating,
  updateApplicationResponse,
  type ApplicationDetail,
  type ApplicationDocument,
  type ApplicationReview,
  type ResponseStatus,
} from '../api/applications'
import { fieldErrorsFrom, summaryMessage } from '../api/validationErrors'
import { FormAlert } from '../components/FormAlert'
import { PipelineStageBadge } from '../components/PipelineStageBadge'
import { APPLICATIONS_ROUTE } from './paths'
import './resumes.css'
import './applications.css'

/**
 * Single-application view (Requirements 10.2, 10.3, 10.4).
 *
 * Shows, in this order: the `needs_review` call-to-action when there is one, the
 * manual status control, the full job description, the score rationale and fit
 * analysis, signed download links for the submitted documents, and the apply
 * automation's own log. The review CTA leads because it is the only section that
 * asks the user for something.
 *
 * The two status vocabularies are deliberately rendered apart — the pipeline's
 * stage in the header, the employer's response in its own section — so a
 * user-reported "rejected" is never mistaken for a failed apply attempt
 * (Requirement 10.4).
 */
export function ApplicationDetailPage() {
  const { applicationId } = useParams()

  const detailQuery = useQuery({
    queryKey: applicationKeys.detail(applicationId ?? ''),
    queryFn: () => fetchApplication(applicationId as string),
    enabled: Boolean(applicationId),
    refetchInterval: (query) => pollIntervalForApplication(query.state.data),
    refetchIntervalInBackground: false,
  })

  const backLink = (
    <p>
      <Link to={APPLICATIONS_ROUTE} className="resume-detail__back">
        ← Back to applications
      </Link>
    </p>
  )

  if (detailQuery.isPending) {
    return (
      <>
        {backLink}
        <p className="state">Loading this application…</p>
      </>
    )
  }

  if (detailQuery.isError) {
    const notFound =
      detailQuery.error instanceof ApiError && detailQuery.error.status === 404

    return (
      <>
        {backLink}
        <div className="state state--error" role="alert">
          <p style={{ margin: 0 }}>
            {notFound
              ? 'That application does not exist, or it is not yours.'
              : summaryMessage(detailQuery.error, false)}
          </p>
          {notFound ? null : (
            <p style={{ margin: '0.5rem 0 0' }}>
              <button
                type="button"
                className="btn btn--secondary"
                onClick={() => void detailQuery.refetch()}
              >
                Try again
              </button>
            </p>
          )}
        </div>
      </>
    )
  }

  const application = detailQuery.data
  const listing = application.job_listing
  const rating = starRating(application.score?.stars)

  return (
    <>
      {backLink}

      <h1 className="page__title">{applicationTitle(application)}</h1>

      <div className="resume-detail__status">
        <PipelineStageBadge stage={listing?.pipeline_stage} />
        {rating ? (
          <span className="stars">
            <span aria-hidden="true">{rating.glyphs}</span>
            <span className="visually-hidden">{rating.label}</span>
          </span>
        ) : (
          <span>Not rated yet</span>
        )}
        <span>{stageDescription(listing?.pipeline_stage)}</span>
      </div>

      <p className="resume-detail__meta">
        {listing?.location ? <>{listing.location} · </> : null}
        {application.applied_at
          ? `Applied ${formatDateTime(application.applied_at)}`
          : `Created ${formatDateTime(application.created_at)}`}
        {application.apply_adapter_used
          ? ` · Submitted by the ${application.apply_adapter_used} adapter`
          : null}
        {listing?.application_url ? (
          <>
            {' · '}
            <a href={listing.application_url} target="_blank" rel="noreferrer noopener">
              Open the original posting
            </a>
          </>
        ) : null}
      </p>

      {application.needs_review && application.review ? (
        <ReviewCallToAction review={application.review} postingUrl={listing?.application_url} />
      ) : null}

      <ResponseStatusSection application={application} />

      <DocumentsSection documents={application.documents} />

      <ScoreSection application={application} />

      <section className="parsed-section">
        <h2 className="parsed-section__title">Job description</h2>
        {listing?.description?.trim() ? (
          <p className="jd">{listing.description}</p>
        ) : (
          <p className="state">
            No description has been stored for this posting yet. Enrichment may
            still be running, or the source blocked the fetch.
          </p>
        )}
      </section>

      <AutomationLogSection application={application} />
    </>
  )
}

/**
 * The `needs_review` call-to-action (Requirement 10.3).
 *
 * `role="alert"` and placed first: the pipeline is stopped until the user acts,
 * so this is not a footnote. Unanswered questions are listed verbatim rather
 * than summarised — the whole reason the automation paused is that it would
 * have had to guess at them.
 */
function ReviewCallToAction({
  review,
  postingUrl,
}: {
  review: ApplicationReview
  postingUrl: string | null | undefined
}) {
  return (
    <section className="review-callout" role="alert" aria-labelledby="review-callout-heading">
      <h2 className="review-callout__title" id="review-callout-heading">
        {review.headline}
      </h2>
      <p className="review-callout__detail">{review.detail}</p>

      {review.questions && review.questions.length > 0 ? (
        <>
          <p className="review-callout__label">Questions waiting on an answer:</p>
          <ul className="review-callout__list">
            {review.questions.map((question, index) => (
              <li key={`${index}-${question}`}>{question}</li>
            ))}
          </ul>
        </>
      ) : null}

      {review.documents && review.documents.length > 0 ? (
        <p className="review-callout__detail">
          Flagged{' '}
          {review.documents.map((type) => documentTypeLabel(type).toLowerCase()).join(' and ')}
          . Download it below and check the highlighted details against your
          profile before using it.
        </p>
      ) : null}

      {postingUrl ? (
        <p className="review-callout__detail">
          You can also{' '}
          <a href={postingUrl} target="_blank" rel="noreferrer noopener">
            apply manually on the posting
          </a>{' '}
          and record the outcome below.
        </p>
      ) : null}
    </section>
  )
}

const RESPONSE_FIELDS = ['response_status', 'response_note'] as const

/**
 * Manual employer-response control (Requirement 10.4).
 *
 * Only `response_status` and a note are submitted; the backend rejects a
 * `status` key outright, so there is deliberately no control here for the
 * automation's own status.
 */
function ResponseStatusSection({ application }: { application: ApplicationDetail }) {
  const queryClient = useQueryClient()
  const current = application.response_status
  const [status, setStatus] = useState<ResponseStatus | ''>(
    RESPONSE_STATUSES.includes(current as ResponseStatus) ? (current as ResponseStatus) : '',
  )
  const [note, setNote] = useState('')
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldError, setFieldError] = useState<string | null>(null)
  const [otherErrors, setOtherErrors] = useState<string[]>([])
  const [saved, setSaved] = useState<string | null>(null)

  const update = useMutation({
    mutationFn: (input: { response_status: ResponseStatus; response_note?: string | null }) =>
      updateApplicationResponse(application.id, input),
    onSuccess: (updated) => {
      setNote('')
      setSaved(`Saved. Response recorded as “${responseStatusLabel(updated.response_status)}”.`)
      // The PATCH returns the same shape as the detail read, so the cache can
      // take it directly instead of waiting on a refetch.
      queryClient.setQueryData(applicationKeys.detail(application.id), updated)
      void queryClient.invalidateQueries({ queryKey: applicationKeys.all })
    },
    onError: (error) => {
      const { fieldErrors, unmappedErrors } = fieldErrorsFrom(error, RESPONSE_FIELDS)
      const hasFieldErrors = Object.keys(fieldErrors).length > 0
      setFieldError(fieldErrors.response_status ?? null)
      setOtherErrors([
        ...unmappedErrors,
        ...(fieldErrors.response_note ? [fieldErrors.response_note] : []),
      ])
      setFormError(summaryMessage(error, hasFieldErrors))
    },
  })

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (update.isPending) return

    setFormError(null)
    setOtherErrors([])
    setSaved(null)

    if (!status) {
      setFieldError('Choose the response you want to record.')
      return
    }

    setFieldError(null)
    update.mutate({
      response_status: status,
      response_note: note.trim() === '' ? null : note.trim(),
    })
  }

  return (
    <section className="parsed-section" aria-labelledby="response-heading">
      <h2 className="parsed-section__title" id="response-heading">
        Employer response
      </h2>
      <p className="response__current">
        Currently recorded: <strong>{responseStatusLabel(current)}</strong>
        <span className="response__hint">
          {' '}
          Tracked separately from the automation&apos;s own status
          {application.status ? ` (${application.status})` : null}.
        </span>
      </p>

      <FormAlert message={formError} extra={otherErrors} />

      <p className="visually-hidden" role="status" aria-live="polite">
        {update.isPending ? 'Saving response…' : (saved ?? '')}
      </p>

      {saved && !update.isPending ? <p className="upload__selected">{saved}</p> : null}

      <form onSubmit={handleSubmit} noValidate className="response__form">
        <div className="upload__field">
          <label htmlFor="response-status">What happened?</label>
          <select
            id="response-status"
            className={fieldError ? 'upload__input upload__input--error' : 'upload__input'}
            value={status}
            aria-invalid={fieldError ? true : undefined}
            aria-describedby={fieldError ? 'response-status-error' : undefined}
            onChange={(event) => {
              setStatus(event.target.value as ResponseStatus | '')
              setFieldError(null)
              setSaved(null)
            }}
          >
            <option value="">Choose a response…</option>
            {RESPONSE_STATUSES.map((value) => (
              <option key={value} value={value}>
                {responseStatusLabel(value)}
              </option>
            ))}
          </select>
          {fieldError ? (
            <p className="upload__error" id="response-status-error">
              {fieldError}
            </p>
          ) : null}
        </div>

        <div className="upload__field">
          <label htmlFor="response-note">Note (optional)</label>
          <textarea
            id="response-note"
            className="upload__input"
            rows={2}
            maxLength={2000}
            value={note}
            onChange={(event) => {
              setNote(event.target.value)
              setSaved(null)
            }}
            placeholder="e.g. Phone screen booked for Friday."
          />
        </div>

        <button type="submit" className="btn" disabled={update.isPending}>
          {update.isPending ? 'Saving…' : 'Record response'}
        </button>
      </form>

      {application.manual_status_history.length > 0 ? (
        <>
          <p className="tag-list__label">Your updates</p>
          <ul className="history">
            {application.manual_status_history
              .slice()
              .reverse()
              .map((entry, index) => (
                <li key={`${entry.recorded_at ?? index}-${index}`} className="history__item">
                  <strong>{responseStatusLabel(entry.response_status)}</strong>{' '}
                  <span className="history__when">
                    {formatDateTime(entry.recorded_at ?? null)}
                  </span>
                  {entry.note ? <p className="history__note">{entry.note}</p> : null}
                </li>
              ))}
          </ul>
        </>
      ) : null}
    </section>
  )
}

/** Score rationale and fit analysis for the current attempt. */
function ScoreSection({ application }: { application: ApplicationDetail }) {
  const score = application.score
  const sections = readFitAnalysis(score?.fit_analysis)

  return (
    <section className="parsed-section">
      <h2 className="parsed-section__title">Why it was rated this way</h2>

      {!score ? (
        <p className="state">
          This job has not been scored for you yet, so there is no rationale to
          show.
        </p>
      ) : (
        <>
          {score.rationale?.trim() ? (
            <p>{score.rationale}</p>
          ) : (
            <p className="state">No rationale text was stored for this score.</p>
          )}

          <p className="resume-detail__meta">
            Attempt {score.attempt_number ?? 1}
            {score.recommended_action
              ? ` · Recommended: ${score.recommended_action.replace(/_/g, ' ')}`
              : null}
            {score.created_at ? ` · Scored ${formatDateTime(score.created_at)}` : null}
          </p>

          {sections.map((section) => (
            <div key={section.label}>
              <p className="tag-list__label">{section.label}</p>
              <ul className="tag-list">
                {section.items.map((item, index) => (
                  <li key={`${index}-${item}`} className="tag">
                    {item}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </>
      )}
    </section>
  )
}

/**
 * Tailored documents with their signed download links (Requirements 10.2, 8.3).
 *
 * Links are signed per request and short-lived, so they are rendered as plain
 * anchors and the page is re-fetched rather than caching a URL anywhere.
 */
function DocumentsSection({ documents }: { documents: ApplicationDocument[] }) {
  return (
    <section className="parsed-section">
      <h2 className="parsed-section__title">Submitted documents</h2>

      {documents.length === 0 ? (
        <p className="state">
          No tailored documents are linked to this application yet.
        </p>
      ) : (
        <ul className="doc-list">
          {documents.map((document) => (
            <li key={document.id} className="doc-list__item">
              <p className="doc-list__name">{documentTypeLabel(document.type)}</p>
              <p className="doc-list__meta">
                {document.status}
                {document.template_key ? ` · ${document.template_key} template` : null}
                {document.generation_model ? ` · ${document.generation_model}` : null}
                {document.created_at ? ` · ${formatDateTime(document.created_at)}` : null}
              </p>

              {document.download_url ? (
                <p>
                  <a
                    className="btn btn--secondary"
                    href={document.download_url}
                    target="_blank"
                    rel="noreferrer noopener"
                  >
                    Download PDF
                    <span className="visually-hidden">
                      {' '}
                      — {documentTypeLabel(document.type)}
                    </span>
                  </a>
                </p>
              ) : (
                <p className="resume-list__reason">
                  <strong>No download available:</strong>{' '}
                  {document.download_error ?? 'The file was never stored.'}
                </p>
              )}

              <FabricationFlags flags={document.fabrication_flags} />
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

/** Fabrication findings for one document, if the guard recorded any (Req 6.6). */
function FabricationFlags({ flags }: { flags: unknown }) {
  if (!flags || typeof flags !== 'object' || Array.isArray(flags)) return null

  const findings = (flags as { findings?: unknown }).findings
  if (!Array.isArray(findings) || findings.length === 0) return null

  return (
    <div className="resume-list__reason">
      <strong>Possible fabrication ({findings.length}):</strong>
      <ul className="review-callout__list">
        {findings.map((finding, index) => (
          <li key={index}>
            {typeof finding === 'string' ? finding : JSON.stringify(finding)}
          </li>
        ))}
      </ul>
    </div>
  )
}

/**
 * What the apply adapter did, and anything that went wrong (Requirement 10.2).
 *
 * The log's shape is per-adapter, so an unrecognised payload falls back to the
 * raw JSON instead of rendering an empty section — the same choice the resume
 * screen makes for unrecognised `parsed_data`.
 */
function AutomationLogSection({ application }: { application: ApplicationDetail }) {
  const log = readAutomationLog(application.automation_log)
  const raw = application.automation_log

  return (
    <section className="parsed-section">
      <h2 className="parsed-section__title">Automation log</h2>

      {!hasAutomationLog(raw) ? (
        <p className="state">
          Nothing has been recorded yet. The apply automation writes its steps,
          screenshots, and any failure here.
        </p>
      ) : log.isEmpty ? (
        <pre className="parsed-raw">{JSON.stringify(raw, null, 2)}</pre>
      ) : (
        <>
          {log.adapter ? (
            <p className="resume-detail__meta">Adapter: {log.adapter}</p>
          ) : null}

          {log.failureReason ? (
            <p className="resume-detail__reason">
              <strong>Stopped:</strong> {log.failureReason}
            </p>
          ) : null}

          {log.steps.length > 0 ? (
            <>
              <p className="tag-list__label">Steps taken</p>
              <ol className="entry__points">
                {log.steps.map((step, index) => (
                  <li key={`${index}-${step}`}>{step}</li>
                ))}
              </ol>
            </>
          ) : null}

          {log.unansweredQuestions.length > 0 ? (
            <>
              <p className="tag-list__label">Unanswered questions</p>
              <ul className="entry__points">
                {log.unansweredQuestions.map((question, index) => (
                  <li key={`${index}-${question}`}>{question}</li>
                ))}
              </ul>
            </>
          ) : null}

          {log.screenshots.length > 0 ? (
            <>
              <p className="tag-list__label">Screenshots captured</p>
              <ul className="entry__points">
                {log.screenshots.map((key, index) => (
                  <li key={`${index}-${key}`}>
                    <code>{key}</code>
                  </li>
                ))}
              </ul>
            </>
          ) : null}
        </>
      )}
    </section>
  )
}
