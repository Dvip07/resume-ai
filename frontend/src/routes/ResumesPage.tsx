import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'

import {
  deleteResume,
  fetchResumes,
  formatBytes,
  formatUploadedAt,
  isPendingStatus,
  pollIntervalForResumeList,
  resumeKeys,
  uploadResume,
  validateResumeFile,
  type Resume,
} from '../api/resumes'
import { fieldErrorsFrom, summaryMessage } from '../api/validationErrors'
import { FormAlert } from '../components/FormAlert'
import { ResumeStatusBadge } from '../components/ResumeStatusBadge'
import { resumeDetailPath } from './paths'
import './resumes.css'

/** The only field the backend's resume validator reports against. */
const UPLOAD_FIELDS = ['resume'] as const

/**
 * Resume list + upload screen (Requirements 2.4, 2.5).
 *
 * Statuses move on a queue worker's schedule, so the list re-fetches itself
 * every few seconds while any row is still `uploaded`/`parsing` and stops as
 * soon as every row has settled on `parsed`/`failed` — a user never has to
 * reload to find out what happened, and a failure always shows its recorded
 * reason instead of sitting in a silent "processing" state (Requirement 2.4).
 */
export function ResumesPage() {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)

  const listQuery = useQuery({
    queryKey: resumeKeys.list(page),
    queryFn: () => fetchResumes(page),
    // Poll only while something is still in flight; `false` stops the timer.
    refetchInterval: (query) => pollIntervalForResumeList(query.state.data),
    refetchIntervalInBackground: false,
  })

  const resumes = listQuery.data?.data ?? []
  const lastPage = listQuery.data?.last_page ?? 1
  const total = listQuery.data?.total ?? 0
  const pendingCount = resumes.filter((resume) => isPendingStatus(resume.status)).length

  // A page can disappear under us after the last row on it is deleted.
  useEffect(() => {
    if (page > 1 && page > lastPage) setPage(lastPage)
  }, [page, lastPage])

  return (
    <>
      <h1 className="page__title">Resumes</h1>
      <p className="resumes__intro">
        Upload a PDF resume and we&apos;ll extract your skills, experience, and
        education from it. Parsing runs in the background — this list updates on
        its own as each resume finishes.
      </p>

      <UploadForm
        onUploaded={() => {
          // Fresh uploads land on page 1 (the API sorts newest first).
          setPage(1)
          void queryClient.invalidateQueries({ queryKey: resumeKeys.all })
        }}
      />

      <h2 className="section-title">
        Your resumes{total > 0 ? ` (${total})` : null}
      </h2>

      <StatusAnnouncer pendingCount={pendingCount} resumes={resumes} />

      {listQuery.isPending ? (
        <p className="state">Loading your resumes…</p>
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
      ) : resumes.length === 0 ? (
        <p className="state">
          No resumes yet. Upload one above to get started.
        </p>
      ) : (
        <>
          <ul className="resume-list">
            {resumes.map((resume) => (
              <ResumeRow key={resume.id} resume={resume} />
            ))}
          </ul>

          {lastPage > 1 ? (
            <nav className="pager" aria-label="Resume pages">
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
 * Polite live region for background status changes.
 *
 * Sighted users see the badges update; this is how a screen reader user learns
 * the same thing without re-reading the list. The text only changes when a
 * status actually changes, so a poll that finds nothing new announces nothing.
 */
function StatusAnnouncer({
  pendingCount,
  resumes,
}: {
  pendingCount: number
  resumes: Resume[]
}) {
  const failedCount = resumes.filter((resume) => resume.status === 'failed').length

  const parts: string[] = []
  if (pendingCount > 0) {
    parts.push(
      `${pendingCount} resume${pendingCount === 1 ? '' : 's'} still being parsed.`,
    )
  } else if (resumes.length > 0) {
    parts.push('All resumes have finished parsing.')
  }
  if (failedCount > 0) {
    parts.push(`${failedCount} failed to parse.`)
  }

  return (
    <p className="visually-hidden" role="status" aria-live="polite">
      {parts.join(' ')}
    </p>
  )
}

/** One row: filename, upload date, status badge, and the failure reason. */
function ResumeRow({ resume }: { resume: Resume }) {
  const queryClient = useQueryClient()
  const [confirming, setConfirming] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)
  const confirmButtonRef = useRef<HTMLButtonElement>(null)

  // Move focus onto the confirm control so a keyboard user lands on the
  // decision they just opened.
  useEffect(() => {
    if (confirming) confirmButtonRef.current?.focus()
  }, [confirming])

  const removal = useMutation({
    mutationFn: () => deleteResume(resume.id),
    onSuccess: () => {
      setConfirming(false)
      void queryClient.invalidateQueries({ queryKey: resumeKeys.all })
      queryClient.removeQueries({ queryKey: resumeKeys.detail(resume.id) })
    },
    onError: (error) => setDeleteError(summaryMessage(error, false)),
  })

  const name = resume.original_filename ?? `Resume #${resume.id}`

  return (
    <li className="resume-list__item">
      <div className="resume-list__main">
        <p className="resume-list__name">
          <Link to={resumeDetailPath(resume.id)}>{name}</Link>
        </p>

        <div className="resume-list__meta">
          <ResumeStatusBadge status={resume.status} />
          <span>Uploaded {formatUploadedAt(resume.created_at)}</span>
        </div>

        {resume.status === 'failed' ? (
          <p className="resume-list__reason">
            <strong>Parsing failed:</strong>{' '}
            {resume.status_error ?? 'No reason was recorded by the server.'}
          </p>
        ) : null}

        {deleteError ? (
          <p className="resume-list__reason" role="alert">
            {deleteError}
          </p>
        ) : null}
      </div>

      <div className="resume-list__actions">
        {confirming ? (
          <span className="resume-list__confirm">
            Delete “{name}”?
            <button
              type="button"
              ref={confirmButtonRef}
              className="btn btn--danger"
              onClick={() => {
                setDeleteError(null)
                removal.mutate()
              }}
              disabled={removal.isPending}
            >
              {removal.isPending ? 'Deleting…' : 'Yes, delete'}
            </button>
            <button
              type="button"
              className="btn btn--secondary"
              onClick={() => setConfirming(false)}
              disabled={removal.isPending}
            >
              Cancel
            </button>
          </span>
        ) : (
          <>
            <Link to={resumeDetailPath(resume.id)} className="btn btn--secondary">
              View details
            </Link>
            <button
              type="button"
              className="btn btn--danger"
              onClick={() => setConfirming(true)}
            >
              Delete
              <span className="visually-hidden"> {name}</span>
            </button>
          </>
        )}
      </div>
    </li>
  )
}

/** File picker + submit, with the backend's PDF/size rules checked up front. */
function UploadForm({ onUploaded }: { onUploaded: () => void }) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [otherErrors, setOtherErrors] = useState<string[]>([])
  const [successMessage, setSuccessMessage] = useState<string | null>(null)

  const upload = useMutation({
    mutationFn: (selected: File) => uploadResume(selected),
    onSuccess: (response) => {
      setFile(null)
      setFileError(null)
      if (inputRef.current) inputRef.current.value = ''
      setSuccessMessage(response.message ?? 'Resume uploaded. Parsing has started.')
      onUploaded()
    },
    onError: (error) => {
      const { fieldErrors, unmappedErrors } = fieldErrorsFrom(error, UPLOAD_FIELDS)
      const hasFieldErrors = Object.keys(fieldErrors).length > 0

      setFileError(fieldErrors.resume ?? null)
      setOtherErrors(unmappedErrors)
      setFormError(summaryMessage(error, hasFieldErrors))
    },
  })

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (upload.isPending) return

    setFormError(null)
    setOtherErrors([])
    setSuccessMessage(null)

    const problem = validateResumeFile(file)
    if (problem || !file) {
      setFileError(problem)
      // Send focus back to the input so the message is discoverable.
      inputRef.current?.focus()
      return
    }

    setFileError(null)
    upload.mutate(file)
  }

  return (
    <section className="upload" aria-labelledby="upload-heading">
      <h2 className="upload__title" id="upload-heading">
        Upload a resume
      </h2>
      <p className="upload__hint">PDF only, up to 10 MB.</p>

      <FormAlert message={formError} extra={otherErrors} />

      <p className="visually-hidden" role="status" aria-live="polite">
        {upload.isPending ? 'Uploading resume…' : (successMessage ?? '')}
      </p>

      {successMessage && !upload.isPending ? (
        <p className="upload__selected">{successMessage}</p>
      ) : null}

      <form onSubmit={handleSubmit} noValidate>
        <div className="upload__field">
          <label htmlFor="resume-file">Resume file (PDF)</label>
          <input
            id="resume-file"
            ref={inputRef}
            type="file"
            name="resume"
            accept="application/pdf,.pdf"
            className={
              fileError ? 'upload__input upload__input--error' : 'upload__input'
            }
            aria-invalid={fileError ? true : undefined}
            aria-describedby={fileError ? 'resume-file-error' : undefined}
            onChange={(event) => {
              const selected = event.target.files?.[0] ?? null
              setFile(selected)
              setFileError(validateResumeFile(selected))
              setSuccessMessage(null)
              setFormError(null)
              setOtherErrors([])
            }}
          />

          {fileError ? (
            <p className="upload__error" id="resume-file-error">
              {fileError}
            </p>
          ) : file ? (
            <p className="upload__selected">
              {file.name} · {formatBytes(file.size)}
            </p>
          ) : null}
        </div>

        <button type="submit" className="btn" disabled={upload.isPending}>
          {upload.isPending ? 'Uploading…' : 'Upload resume'}
        </button>
      </form>
    </section>
  )
}
