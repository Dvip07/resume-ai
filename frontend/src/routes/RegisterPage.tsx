import { useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'

import { fieldErrorsFrom, summaryMessage, type FieldErrors } from '../api/validationErrors'
import { intendedDestination, LOGIN_ROUTE } from '../auth/authContext'
import { useAuth } from '../auth/useAuth'
import { FormAlert } from '../components/FormAlert'
import { TextField } from '../components/TextField'
import './auth.css'

/**
 * Fields the backend's register validator reports against. `password` carries
 * the `confirmed` rule, so a mismatch comes back keyed as
 * `password_confirmation` — both are listed.
 */
const REGISTER_FIELDS = ['name', 'email', 'password', 'password_confirmation'] as const
type RegisterField = (typeof REGISTER_FIELDS)[number]

/** Mirrors the backend rule `password => min:8` so we can fail fast. */
const MIN_PASSWORD_LENGTH = 8

/** Registration screen backed by `POST /api/auth/register` (Requirement 1.4). */
export function RegisterPage() {
  const { register } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<RegisterField>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [otherErrors, setOtherErrors] = useState<string[]>([])

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitting) return

    // Catch the mismatch locally: the backend's `confirmed` rule would report
    // it too, but there's no reason to spend a round trip on it.
    if (password !== passwordConfirmation) {
      setFieldErrors({ password_confirmation: 'The passwords do not match.' })
      setOtherErrors([])
      setFormError('Please correct the highlighted fields.')
      return
    }

    setSubmitting(true)
    setFieldErrors({})
    setFormError(null)
    setOtherErrors([])

    try {
      await register({
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
      })
      navigate(intendedDestination(location.state), { replace: true })
    } catch (error) {
      const { fieldErrors: fields, unmappedErrors } = fieldErrorsFrom(error, REGISTER_FIELDS)
      const hasFieldErrors = Object.keys(fields).length > 0

      setFieldErrors(fields)
      setOtherErrors(unmappedErrors)
      setFormError(summaryMessage(error, hasFieldErrors))
      setPassword('')
      setPasswordConfirmation('')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="auth">
      <div className="auth__card">
        <h1>Create your account</h1>
        <p className="auth__intro">All fields are required.</p>

        <FormAlert message={formError} extra={otherErrors} />

        <form onSubmit={handleSubmit} noValidate>
          <TextField
            label="Name"
            type="text"
            name="name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            autoComplete="name"
            required
            error={fieldErrors.name}
          />

          <TextField
            label="Email"
            type="email"
            name="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoComplete="email"
            required
            error={fieldErrors.email}
          />

          <TextField
            label="Password"
            type="password"
            name="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            autoComplete="new-password"
            minLength={MIN_PASSWORD_LENGTH}
            hint={`At least ${MIN_PASSWORD_LENGTH} characters.`}
            required
            error={fieldErrors.password}
          />

          <TextField
            label="Confirm password"
            type="password"
            name="password_confirmation"
            value={passwordConfirmation}
            onChange={(event) => setPasswordConfirmation(event.target.value)}
            autoComplete="new-password"
            required
            error={fieldErrors.password_confirmation}
          />

          <button type="submit" className="auth__submit" disabled={submitting}>
            {submitting ? 'Creating account…' : 'Create account'}
          </button>
        </form>

        <p className="auth__switch">
          Already registered? <Link to={LOGIN_ROUTE}>Sign in</Link>
        </p>
      </div>
    </main>
  )
}
