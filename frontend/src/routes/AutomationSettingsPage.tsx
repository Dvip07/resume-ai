import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'

import {
  automationSettingsKeys,
  fetchAutomationSettings,
  LINKEDIN_RISKS,
  updateAutomationSettings,
  type AutomationSettings,
} from '../api/automationSettings'
import { allFieldErrorsFrom, summaryMessage } from '../api/validationErrors'
import { FormAlert } from '../components/FormAlert'
// Shared primitives: `.field*`/`.form-alert*` live in auth.css,
// `.btn*`/`.state` in resumes.css. Imported explicitly so this screen is styled
// even when it is the first one rendered in dev.
import './auth.css'
import './resumes.css'
import './automation.css'

/**
 * Automation settings (Requirements 9.1, 9.3, 9.7).
 *
 * The screen exists for one control that cannot be a plain switch: the LinkedIn
 * Easy Apply opt-in. Easy Apply is driven through the user's own signed-in
 * LinkedIn session, automating it arguably breaches LinkedIn's User Agreement,
 * and the thing at risk is the account itself — which this platform cannot give
 * back. So enabling it is a two-part act: read the risks, tick the
 * acknowledgment, then switch it on. The acknowledgment travels with the
 * request and the server refuses the change without it, so the checkbox is a
 * real gate rather than a UI flourish.
 *
 * Turning it off is one click with no ceremony. Withdrawing consent must never
 * be harder than giving it.
 */
export function AutomationSettingsPage() {
  const query = useQuery({
    queryKey: automationSettingsKeys.detail,
    queryFn: fetchAutomationSettings,
  })

  return (
    <>
      <h1 className="page__title">Automation settings</h1>
      <p className="automation__intro">
        Controls what this platform is allowed to do on your behalf, and how
        often. Everything here is off until you turn it on.
      </p>

      {query.isPending ? (
        <p className="state">Loading your automation settings…</p>
      ) : query.isError ? (
        <div className="state state--error" role="alert">
          <p style={{ margin: 0 }}>{summaryMessage(query.error, false)}</p>
          <p style={{ margin: '0.5rem 0 0' }}>
            <button
              type="button"
              className="btn btn--secondary"
              onClick={() => void query.refetch()}
            >
              Try again
            </button>
          </p>
        </div>
      ) : (
        <LinkedInOptIn settings={query.data} />
      )}
    </>
  )
}

function LinkedInOptIn({ settings }: { settings: AutomationSettings }) {
  const queryClient = useQueryClient()
  const enabled = settings.linkedin_auto_apply_opt_in

  const [acknowledged, setAcknowledged] = useState(false)

  // The acknowledgment is about one change, not a stored fact: once the setting
  // flips (either way) the tick is cleared, so turning it on again asks again.
  useEffect(() => {
    setAcknowledged(false)
  }, [enabled])

  const mutation = useMutation({
    mutationFn: updateAutomationSettings,
    onSuccess: (updated) => {
      queryClient.setQueryData(automationSettingsKeys.detail, updated)
    },
  })

  const fieldErrors = allFieldErrorsFrom(mutation.error)
  const acknowledgmentError = fieldErrors.linkedin_risk_acknowledged
  const summary = summaryMessage(mutation.error, Object.keys(fieldErrors).length > 0)

  const effectiveCap = Math.min(
    settings.linkedin_daily_apply_cap,
    settings.linkedin.platform_daily_cap,
  )

  return (
    <section className="automation-card" aria-labelledby="linkedin-opt-in-heading">
      <div className="automation-card__header">
        <h2 className="automation-card__title" id="linkedin-opt-in-heading">
          LinkedIn Easy Apply
        </h2>
        <p className="automation-card__status">
          <span
            className={enabled ? 'badge badge--on' : 'badge badge--off'}
            // The state is in the text too, not only the colour.
          >
            {enabled ? 'Enabled' : 'Off'}
          </span>
        </p>
      </div>

      <p className="automation-card__body">
        When this is off, LinkedIn postings are never driven automatically. Your
        tailored resume and cover letter are still prepared, and the application
        is handed to you to submit yourself.
      </p>

      <div className="automation-risk">
        <h3 className="automation-risk__title">Before you enable this</h3>
        <ul className="automation-risk__list">
          {LINKEDIN_RISKS.map((risk) => (
            <li key={risk}>{risk}</li>
          ))}
        </ul>
        <p className="automation-risk__footnote">
          LinkedIn applications are capped at {effectiveCap} a day
          {settings.linkedin_daily_apply_cap > settings.linkedin.platform_daily_cap
            ? ` (the platform limit is ${settings.linkedin.platform_daily_cap})`
            : ''}
          , separately from your overall daily limit of {settings.daily_apply_cap}.
        </p>
      </div>

      <FormAlert message={summary} />

      {enabled ? (
        <div className="automation-card__actions">
          <button
            type="button"
            className="btn btn--secondary"
            disabled={mutation.isPending}
            onClick={() =>
              mutation.mutate({ linkedin_auto_apply_opt_in: false })
            }
          >
            {mutation.isPending ? 'Turning off…' : 'Turn off LinkedIn Easy Apply'}
          </button>
        </div>
      ) : (
        <>
          <div className="field field--check">
            <label className="field__check-label" htmlFor="linkedin-acknowledge">
              <input
                id="linkedin-acknowledge"
                type="checkbox"
                checked={acknowledged}
                aria-describedby={
                  acknowledgmentError ? 'linkedin-acknowledge-error' : undefined
                }
                aria-invalid={acknowledgmentError ? true : undefined}
                onChange={(event) => setAcknowledged(event.target.checked)}
              />
              <span>
                I understand that this uses my own LinkedIn account, may violate
                LinkedIn&apos;s terms of service, and could get my account
                restricted or closed. I accept that risk.
              </span>
            </label>
            {acknowledgmentError ? (
              <p className="field__error" id="linkedin-acknowledge-error" role="alert">
                {acknowledgmentError}
              </p>
            ) : null}
          </div>

          <div className="automation-card__actions">
            <button
              type="button"
              className="btn btn--primary"
              // Disabled, and the server would refuse it anyway — the
              // acknowledgment is enforced on both sides.
              disabled={!acknowledged || mutation.isPending}
              onClick={() =>
                mutation.mutate({
                  linkedin_auto_apply_opt_in: true,
                  linkedin_risk_acknowledged: true,
                })
              }
            >
              {mutation.isPending ? 'Enabling…' : 'Enable LinkedIn Easy Apply'}
            </button>
            {!acknowledged ? (
              <p className="automation-card__hint">
                Tick the box above to enable this.
              </p>
            ) : null}
          </div>
        </>
      )}
    </section>
  )
}
