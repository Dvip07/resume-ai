import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'

import {
  EMPTY_EDUCATION,
  EMPTY_EXPERIENCE,
  educationLabel,
  experienceLabel,
  fetchProfile,
  isProfileMissing,
  profileKeys,
  toProfileForm,
  toProfilePayload,
  updateProfile,
  type ProfileEducation,
  type ProfileExperience,
  type ProfileForm,
} from '../api/profile'
import { allFieldErrorsFrom, summaryMessage } from '../api/validationErrors'
import { FormAlert } from '../components/FormAlert'
import { TextField } from '../components/TextField'
// Shared form primitives currently live in these two sheets: `.field*` and
// `.form-alert*` in auth.css, `.btn*`/`.state`/`.visually-hidden` in
// resumes.css. Imported explicitly so this screen is styled even when it's the
// first one rendered in dev.
import './auth.css'
import './resumes.css'
import './profile.css'

/**
 * Profile screen (Requirement 2.5).
 *
 * The resume parser fills this in automatically and imperfectly, so every
 * extracted field is editable here: skills (primary/secondary), location,
 * social links, and the experience/education lists.
 *
 * A user who has never had a profile created gets a 404 from `GET /profile`.
 * That's the normal starting state, not a failure — it opens an empty form and
 * `PATCH /profile` creates the row on first save.
 */
export function ProfilePage() {
  const profileQuery = useQuery({
    queryKey: profileKeys.detail,
    queryFn: fetchProfile,
    retry: false,
  })

  const missing = profileQuery.isError && isProfileMissing(profileQuery.error)

  return (
    <>
      <h1 className="page__title">Profile</h1>
      <p className="profile__intro">
        These details come from your parsed resumes and are used to score and
        tailor applications. Extraction isn&apos;t perfect — correct anything
        that&apos;s wrong or missing.
      </p>

      {profileQuery.isPending ? (
        <p className="state">Loading your profile…</p>
      ) : profileQuery.isError && !missing ? (
        <div className="state state--error" role="alert">
          <p style={{ margin: 0 }}>{summaryMessage(profileQuery.error, false)}</p>
          <p style={{ margin: '0.5rem 0 0' }}>
            <button
              type="button"
              className="btn btn--secondary"
              onClick={() => void profileQuery.refetch()}
            >
              Try again
            </button>
          </p>
        </div>
      ) : (
        <ProfileEditor
          initial={toProfileForm(profileQuery.data)}
          isNew={missing}
        />
      )}
    </>
  )
}

/* ------------------------------------------------------------ error mapping */

/** Lookup of server error keys, e.g. `experience.0.company`. */
type ErrorMap = (key: string) => string | undefined

function errorLookup(errors: Record<string, string>): ErrorMap {
  return (key) => errors[key]
}

/**
 * Every server key this form is able to render against an input, derived from
 * the current form shape.
 *
 * Laravel keys nested errors by index, so the set has to be built from the
 * live entry counts. Anything outside it (an unknown-key rejection, a message
 * for a column this screen doesn't draw) is reported in the form-level alert
 * rather than silently dropped.
 */
function displayableErrorKeys(form: ProfileForm): Set<string> {
  const keys = new Set<string>([
    'skills',
    'skills.primary',
    'skills.secondary',
    'location',
    'location.city',
    'location.state',
    'location.country',
    'linkedin_url',
    'github_url',
    'portfolio_url',
    'experience',
    'education',
  ])

  form.skills.primary.forEach((_, index) => keys.add(`skills.primary.${index}`))
  form.skills.secondary.forEach((_, index) => keys.add(`skills.secondary.${index}`))

  form.experience.forEach((entry, index) => {
    keys.add(`experience.${index}`)
    for (const field of ['company', 'position', 'duration', 'achievements']) {
      keys.add(`experience.${index}.${field}`)
    }
    entry.achievements.forEach((_, position) =>
      keys.add(`experience.${index}.achievements.${position}`),
    )
  })

  form.education.forEach((_, index) => {
    keys.add(`education.${index}`)
    for (const field of ['institution', 'degree', 'fieldOfStudy', 'startYear', 'endYear']) {
      keys.add(`education.${index}.${field}`)
    }
  })

  return keys
}

/** Messages that no input on this screen will show, prefixed with their key. */
function unmappedMessages(errors: Record<string, string>, form: ProfileForm): string[] {
  const displayable = displayableErrorKeys(form)

  return Object.entries(errors)
    .filter(([key]) => !displayable.has(key))
    .map(([key, message]) => `${key}: ${message}`)
}

/* ------------------------------------------------------------------ editor */

function ProfileEditor({ initial, isNew }: { initial: ProfileForm; isNew: boolean }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState<ProfileForm>(initial)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [savedAt, setSavedAt] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: () => updateProfile(toProfilePayload(form)),
    onSuccess: (profile) => {
      setErrors({})
      setFormError(null)
      // Re-seed from what the server actually stored, so the form shows the
      // trimmed/normalised values rather than the raw typed ones.
      setForm(toProfileForm(profile))
      setSavedAt(new Date().toLocaleTimeString())
      queryClient.setQueryData(profileKeys.detail, profile)
    },
    onError: (error) => {
      const fieldErrors = allFieldErrorsFrom(error)
      setErrors(fieldErrors)
      setFormError(summaryMessage(error, Object.keys(fieldErrors).length > 0))
      setSavedAt(null)
    },
  })

  const errorMap = errorLookup(errors)

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (save.isPending) return
    setSavedAt(null)
    save.mutate()
  }

  function patch(changes: Partial<ProfileForm>) {
    setForm((current) => ({ ...current, ...changes }))
  }

  return (
    <form onSubmit={handleSubmit} noValidate>
      {isNew ? (
        <p className="profile__notice">
          You don&apos;t have a profile yet. Fill in whatever you know and save —
          uploading a resume will fill in the rest automatically.
        </p>
      ) : null}

      <FormAlert message={formError} extra={unmappedMessages(errors, form)} />

      {/* Save outcome is announced here and shown in text below, so it never
          depends on noticing a colour change. */}
      <p className="visually-hidden" role="status" aria-live="polite">
        {save.isPending
          ? 'Saving profile…'
          : savedAt
            ? 'Profile saved.'
            : ''}
      </p>

      {savedAt && !save.isPending ? (
        <p className="profile__saved">
          <span className="profile__saved-glyph" aria-hidden="true">
            ✓
          </span>
          Profile saved at {savedAt}.
        </p>
      ) : null}

      <section className="profile-section" aria-labelledby="skills-heading">
        <h2 className="profile-section__title" id="skills-heading">
          Skills
        </h2>
        <p className="profile-section__hint">
          Primary skills are the ones you want to be matched on. Secondary
          skills are supporting or nice-to-have.
        </p>

        <GroupError message={errorMap('skills')} />

        <StringListEditor
          legend="Primary skills"
          itemLabel="Primary skill"
          addLabel="Add primary skill"
          emptyText="No primary skills yet."
          items={form.skills.primary}
          errorFor={(index) => errorMap(`skills.primary.${index}`)}
          groupError={errorMap('skills.primary')}
          onChange={(primary) => patch({ skills: { ...form.skills, primary } })}
        />

        <StringListEditor
          legend="Secondary skills"
          itemLabel="Secondary skill"
          addLabel="Add secondary skill"
          emptyText="No secondary skills yet."
          items={form.skills.secondary}
          errorFor={(index) => errorMap(`skills.secondary.${index}`)}
          groupError={errorMap('skills.secondary')}
          onChange={(secondary) => patch({ skills: { ...form.skills, secondary } })}
        />
      </section>

      <section className="profile-section" aria-labelledby="location-heading">
        <h2 className="profile-section__title" id="location-heading">
          Location
        </h2>
        <p className="profile-section__hint">
          Used to prioritise nearby and remote-eligible roles.
        </p>

        <GroupError message={errorMap('location')} />

        <div className="profile-grid">
          <TextField
            label="City"
            value={form.location.city}
            autoComplete="address-level2"
            error={errorMap('location.city')}
            onChange={(event) =>
              patch({ location: { ...form.location, city: event.target.value } })
            }
          />
          <TextField
            label="State or province"
            value={form.location.state}
            autoComplete="address-level1"
            error={errorMap('location.state')}
            onChange={(event) =>
              patch({ location: { ...form.location, state: event.target.value } })
            }
          />
          <TextField
            label="Country"
            value={form.location.country}
            autoComplete="country-name"
            error={errorMap('location.country')}
            onChange={(event) =>
              patch({ location: { ...form.location, country: event.target.value } })
            }
          />
        </div>
      </section>

      <section className="profile-section" aria-labelledby="links-heading">
        <h2 className="profile-section__title" id="links-heading">
          Links
        </h2>
        <p className="profile-section__hint">
          Full URLs including <code>https://</code>. Leave a field blank to
          remove it.
        </p>

        <TextField
          label="LinkedIn URL"
          type="url"
          inputMode="url"
          value={form.linkedin_url}
          placeholder="https://linkedin.com/in/you"
          error={errorMap('linkedin_url')}
          onChange={(event) => patch({ linkedin_url: event.target.value })}
        />
        <TextField
          label="GitHub URL"
          type="url"
          inputMode="url"
          value={form.github_url}
          placeholder="https://github.com/you"
          error={errorMap('github_url')}
          onChange={(event) => patch({ github_url: event.target.value })}
        />
        <TextField
          label="Portfolio URL"
          type="url"
          inputMode="url"
          value={form.portfolio_url}
          placeholder="https://example.com"
          error={errorMap('portfolio_url')}
          onChange={(event) => patch({ portfolio_url: event.target.value })}
        />
      </section>

      <section className="profile-section" aria-labelledby="experience-heading">
        <h2 className="profile-section__title" id="experience-heading">
          Experience
        </h2>
        <p className="profile-section__hint">
          One entry per role, most recent first. Achievements are reused when
          tailoring resumes, so keep them factual.
        </p>

        <GroupError message={errorMap('experience')} />

        {form.experience.length === 0 ? (
          <p className="list-editor__empty">No experience entries yet.</p>
        ) : (
          form.experience.map((entry, index) => (
            <ExperienceEditor
              key={index}
              index={index}
              entry={entry}
              errorMap={errorMap}
              onChange={(updated) =>
                patch({
                  experience: form.experience.map((item, position) =>
                    position === index ? updated : item,
                  ),
                })
              }
              onRemove={() =>
                patch({
                  experience: form.experience.filter((_, position) => position !== index),
                })
              }
            />
          ))
        )}

        <button
          type="button"
          className="btn btn--secondary"
          onClick={() => patch({ experience: [...form.experience, { ...EMPTY_EXPERIENCE }] })}
        >
          Add experience
        </button>
      </section>

      <section className="profile-section" aria-labelledby="education-heading">
        <h2 className="profile-section__title" id="education-heading">
          Education
        </h2>
        <p className="profile-section__hint">Years are four digits, e.g. 2019.</p>

        <GroupError message={errorMap('education')} />

        {form.education.length === 0 ? (
          <p className="list-editor__empty">No education entries yet.</p>
        ) : (
          form.education.map((entry, index) => (
            <EducationEditor
              key={index}
              index={index}
              entry={entry}
              errorMap={errorMap}
              onChange={(updated) =>
                patch({
                  education: form.education.map((item, position) =>
                    position === index ? updated : item,
                  ),
                })
              }
              onRemove={() =>
                patch({
                  education: form.education.filter((_, position) => position !== index),
                })
              }
            />
          ))
        )}

        <button
          type="button"
          className="btn btn--secondary"
          onClick={() => patch({ education: [...form.education, { ...EMPTY_EDUCATION }] })}
        >
          Add education
        </button>
      </section>

      <div className="profile-actions">
        <button type="submit" className="btn" disabled={save.isPending}>
          {save.isPending ? 'Saving…' : 'Save profile'}
        </button>
        <span className="profile-actions__hint">
          Blank fields are cleared from your profile when you save.
        </span>
      </div>
    </form>
  )
}

/** Section- or group-scoped server message (no matching single input). */
function GroupError({ message }: { message?: string }) {
  if (!message) return null
  return <p className="profile-section__error">{message}</p>
}

/* ------------------------------------------------------------ list editing */

interface StringListEditorProps {
  legend: string
  itemLabel: string
  addLabel: string
  emptyText: string
  items: string[]
  onChange: (items: string[]) => void
  errorFor: (index: number) => string | undefined
  groupError?: string
}

/**
 * Repeatable single-line inputs (skills, achievements).
 *
 * Each row is its own labelled input rather than one comma-separated box, so a
 * server error on `skills.primary.2` can be shown against exactly that value.
 */
function StringListEditor({
  legend,
  itemLabel,
  addLabel,
  emptyText,
  items,
  onChange,
  errorFor,
  groupError,
}: StringListEditorProps) {
  return (
    <fieldset className="list-editor">
      <legend className="list-editor__legend">{legend}</legend>

      <GroupError message={groupError} />

      {items.length === 0 ? (
        <p className="list-editor__empty">{emptyText}</p>
      ) : (
        <ul className="list-editor__rows">
          {items.map((item, index) => (
            <li className="list-editor__row" key={index}>
              <TextField
                label={`${itemLabel} ${index + 1}`}
                value={item}
                error={errorFor(index)}
                onChange={(event) =>
                  onChange(
                    items.map((current, position) =>
                      position === index ? event.target.value : current,
                    ),
                  )
                }
              />
              <button
                type="button"
                className="btn btn--danger list-editor__remove"
                onClick={() => onChange(items.filter((_, position) => position !== index))}
              >
                Remove
                <span className="visually-hidden">
                  {' '}
                  {itemLabel} {index + 1}
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}

      <button type="button" className="btn btn--link" onClick={() => onChange([...items, ''])}>
        {addLabel}
      </button>
    </fieldset>
  )
}

/* -------------------------------------------------------------- entry cards */

function ExperienceEditor({
  index,
  entry,
  errorMap,
  onChange,
  onRemove,
}: {
  index: number
  entry: ProfileExperience
  errorMap: ErrorMap
  onChange: (entry: ProfileExperience) => void
  onRemove: () => void
}) {
  const label = experienceLabel(entry, index)

  return (
    <fieldset className="entry-editor">
      {/* `legend` must be the first child of `fieldset` to name the group, so
          the remove control is positioned rather than wrapped alongside it. */}
      <legend className="entry-editor__title">{label}</legend>
      <button
        type="button"
        className="btn btn--danger entry-editor__remove"
        onClick={onRemove}
      >
        Remove
        <span className="visually-hidden"> {label}</span>
      </button>

      <GroupError message={errorMap(`experience.${index}`)} />

      <div className="profile-grid">
        <TextField
          label="Job title"
          value={entry.position}
          error={errorMap(`experience.${index}.position`)}
          onChange={(event) => onChange({ ...entry, position: event.target.value })}
        />
        <TextField
          label="Company"
          value={entry.company}
          error={errorMap(`experience.${index}.company`)}
          onChange={(event) => onChange({ ...entry, company: event.target.value })}
        />
        <TextField
          label="Duration"
          value={entry.duration}
          hint="Months, or free text like “2 years”."
          error={errorMap(`experience.${index}.duration`)}
          onChange={(event) => onChange({ ...entry, duration: event.target.value })}
        />
      </div>

      <StringListEditor
        legend="Achievements"
        itemLabel="Achievement"
        addLabel="Add achievement"
        emptyText="No achievements recorded for this role."
        items={entry.achievements}
        groupError={errorMap(`experience.${index}.achievements`)}
        errorFor={(position) => errorMap(`experience.${index}.achievements.${position}`)}
        onChange={(achievements) => onChange({ ...entry, achievements })}
      />
    </fieldset>
  )
}

function EducationEditor({
  index,
  entry,
  errorMap,
  onChange,
  onRemove,
}: {
  index: number
  entry: ProfileEducation
  errorMap: ErrorMap
  onChange: (entry: ProfileEducation) => void
  onRemove: () => void
}) {
  const label = educationLabel(entry, index)

  return (
    <fieldset className="entry-editor">
      <legend className="entry-editor__title">{label}</legend>
      <button
        type="button"
        className="btn btn--danger entry-editor__remove"
        onClick={onRemove}
      >
        Remove
        <span className="visually-hidden"> {label}</span>
      </button>

      <GroupError message={errorMap(`education.${index}`)} />

      <div className="profile-grid">
        <TextField
          label="Institution"
          value={entry.institution}
          error={errorMap(`education.${index}.institution`)}
          onChange={(event) => onChange({ ...entry, institution: event.target.value })}
        />
        <TextField
          label="Degree"
          value={entry.degree}
          error={errorMap(`education.${index}.degree`)}
          onChange={(event) => onChange({ ...entry, degree: event.target.value })}
        />
        <TextField
          label="Field of study"
          value={entry.fieldOfStudy}
          error={errorMap(`education.${index}.fieldOfStudy`)}
          onChange={(event) => onChange({ ...entry, fieldOfStudy: event.target.value })}
        />
        <TextField
          label="Start year"
          inputMode="numeric"
          value={entry.startYear}
          error={errorMap(`education.${index}.startYear`)}
          onChange={(event) => onChange({ ...entry, startYear: event.target.value })}
        />
        <TextField
          label="End year"
          inputMode="numeric"
          value={entry.endYear}
          error={errorMap(`education.${index}.endYear`)}
          onChange={(event) => onChange({ ...entry, endYear: event.target.value })}
        />
      </div>
    </fieldset>
  )
}
