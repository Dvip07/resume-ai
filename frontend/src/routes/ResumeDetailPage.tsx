import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'

import { ApiError } from '../api/client'
import {
  fetchResume,
  formatUploadedAt,
  hasParsedData,
  isPendingStatus,
  pollIntervalForResume,
  readParsedData,
  resumeKeys,
  statusDescription,
  type ParsedEducation,
  type ParsedExperience,
  type Resume,
} from '../api/resumes'
import { summaryMessage } from '../api/validationErrors'
import { ResumeStatusBadge } from '../components/ResumeStatusBadge'
import { RESUMES_ROUTE } from './paths'
import './resumes.css'

/**
 * Single-resume screen (Requirements 2.4, 2.5): parsing status, the recorded
 * failure reason when there is one, and the extracted profile data rendered as
 * readable sections.
 *
 * Like the list, this polls itself while the record is still `uploaded` or
 * `parsing` and stops once it settles, so a resume opened right after upload
 * fills in without a reload.
 */
export function ResumeDetailPage() {
  const { resumeId } = useParams<{ resumeId: string }>()

  const resumeQuery = useQuery({
    queryKey: resumeKeys.detail(resumeId ?? ''),
    queryFn: () => fetchResume(resumeId as string),
    enabled: Boolean(resumeId),
    refetchInterval: (query) => pollIntervalForResume(query.state.data),
    refetchIntervalInBackground: false,
  })

  const resume = resumeQuery.data

  return (
    <>
      <p>
        <Link to={RESUMES_ROUTE} className="resume-detail__back">
          ← All resumes
        </Link>
      </p>

      {resumeQuery.isPending ? (
        <p className="state">Loading resume…</p>
      ) : resumeQuery.isError ? (
        <NotFoundOrError error={resumeQuery.error} />
      ) : resume ? (
        <ResumeDetail resume={resume} />
      ) : null}
    </>
  )
}

function NotFoundOrError({ error }: { error: unknown }) {
  const missing = error instanceof ApiError && error.status === 404

  return (
    <div className="state state--error" role="alert">
      <h1 className="page__title">{missing ? 'Resume not found' : 'Could not load resume'}</h1>
      <p style={{ margin: 0 }}>
        {missing
          ? 'This resume either does not exist or belongs to another account.'
          : summaryMessage(error, false)}
      </p>
    </div>
  )
}

function ResumeDetail({ resume }: { resume: Resume }) {
  const parsed = readParsedData(resume.parsed_data)
  const pending = isPendingStatus(resume.status)
  const name = resume.original_filename ?? `Resume #${resume.id}`

  return (
    <>
      <h1 className="page__title">{name}</h1>

      <p className="resume-detail__status">
        <ResumeStatusBadge status={resume.status} />
        <span>{statusDescription(resume)}</span>
      </p>

      {/* Announces the status change the polling picked up, for users who
          aren't watching the badge. */}
      <p className="visually-hidden" role="status" aria-live="polite">
        {pending
          ? `Parsing in progress for ${name}.`
          : `Parsing ${resume.status === 'failed' ? 'failed' : 'finished'} for ${name}.`}
      </p>

      <p className="resume-detail__meta">
        Uploaded {formatUploadedAt(resume.created_at)}
        {resume.updated_at && resume.updated_at !== resume.created_at
          ? ` · Last updated ${formatUploadedAt(resume.updated_at)}`
          : null}
      </p>

      {resume.status === 'failed' ? (
        <div className="resume-detail__reason">
          <strong>Parsing failed.</strong>{' '}
          {resume.status_error ??
            'The server did not record a reason. Try uploading the file again.'}
        </div>
      ) : null}

      {pending ? (
        <p className="state">
          Extracted details will appear here as soon as parsing finishes. This
          page checks for updates automatically.
        </p>
      ) : !hasParsedData(resume) ? (
        <p className="state">
          No extracted details were stored for this resume.
        </p>
      ) : parsed.isEmpty ? (
        <section className="parsed-section">
          <h2 className="parsed-section__title">Extracted data</h2>
          <p className="upload__hint">
            The parser returned data in a shape this screen doesn&apos;t
            recognise, so it&apos;s shown as-is.
          </p>
          <pre className="parsed-raw">{JSON.stringify(resume.parsed_data, null, 2)}</pre>
        </section>
      ) : (
        <>
          {parsed.summary ? (
            <section className="parsed-section">
              <h2 className="parsed-section__title">Summary</h2>
              <p>{parsed.summary}</p>
            </section>
          ) : null}

          {parsed.skills.primary.length > 0 ||
          parsed.skills.secondary.length > 0 ||
          parsed.skills.other.length > 0 ? (
            <section className="parsed-section">
              <h2 className="parsed-section__title">Skills</h2>
              <TagGroup label="Primary" items={parsed.skills.primary} />
              <TagGroup label="Secondary" items={parsed.skills.secondary} />
              <TagGroup label="Other" items={parsed.skills.other} />
            </section>
          ) : null}

          {parsed.experience.length > 0 ? (
            <section className="parsed-section">
              <h2 className="parsed-section__title">Experience</h2>
              <ul className="entry-list">
                {parsed.experience.map((entry, index) => (
                  <ExperienceEntry key={index} entry={entry} />
                ))}
              </ul>
            </section>
          ) : null}

          {parsed.education.length > 0 ? (
            <section className="parsed-section">
              <h2 className="parsed-section__title">Education</h2>
              <ul className="entry-list">
                {parsed.education.map((entry, index) => (
                  <EducationEntry key={index} entry={entry} />
                ))}
              </ul>
            </section>
          ) : null}

          {parsed.keywords.length > 0 ? (
            <section className="parsed-section">
              <h2 className="parsed-section__title">Keywords</h2>
              <TagGroup items={parsed.keywords} />
            </section>
          ) : null}
        </>
      )}
    </>
  )
}

function TagGroup({ label, items }: { label?: string; items: string[] }) {
  if (items.length === 0) return null

  return (
    <>
      {label ? <p className="tag-list__label">{label}</p> : null}
      <ul className="tag-list">
        {items.map((item, index) => (
          <li key={`${item}-${index}`} className="tag">
            {item}
          </li>
        ))}
      </ul>
    </>
  )
}

function ExperienceEntry({ entry }: { entry: ParsedExperience }) {
  const meta = [entry.company, entry.duration].filter(Boolean).join(' · ')

  return (
    <li className="entry">
      <p className="entry__heading">{entry.position ?? entry.company ?? 'Role'}</p>
      {meta ? <p className="entry__meta">{meta}</p> : null}
      {entry.achievements.length > 0 ? (
        <ul className="entry__points">
          {entry.achievements.map((point, index) => (
            <li key={index}>{point}</li>
          ))}
        </ul>
      ) : null}
    </li>
  )
}

function EducationEntry({ entry }: { entry: ParsedEducation }) {
  const meta = [entry.fieldOfStudy, entry.years].filter(Boolean).join(' · ')

  return (
    <li className="entry">
      <p className="entry__heading">
        {entry.degree ?? entry.institution ?? 'Qualification'}
      </p>
      {entry.institution && entry.degree ? (
        <p className="entry__meta">{entry.institution}</p>
      ) : null}
      {meta ? <p className="entry__meta">{meta}</p> : null}
    </li>
  )
}
