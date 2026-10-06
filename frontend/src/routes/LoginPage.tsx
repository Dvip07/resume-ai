import { useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'

import { fieldErrorsFrom, summaryMessage, type FieldErrors } from '../api/validationErrors'
import { intendedDestination, REGISTER_ROUTE } from '../auth/authContext'
import { useAuth } from '../auth/useAuth'
import { FormAlert } from '../components/FormAlert'
import { TextField } from '../components/TextField'
import './auth.css'

/** Fields the backend's login validator can report against. */
const LOGIN_FIELDS = ['email', 'password'] as const
type LoginField = (typeof LOGIN_FIELDS)[number]

/**
 * Sign-in screen backed by `POST /api/auth/login` (Requirement 1.4).
 *
 * On success the user is sent to `state.from` — the destination they were
 * heading for before `ProtectedRoute`/the 401 handler intercepted them
 * (Requirement 1.6) — falling back to the default landing route.
 */
export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<LoginField>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [otherErrors, setOtherErrors] = useState<string[]>([])

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitting) return

    setSubmitting(true)
    setFieldErrors({})
    setFormError(null)
    setOtherErrors([])

    try {
      await login({ email, password })
      navigate(intendedDestination(location.state), { replace: true })
    } catch (error) {
      const { fieldErrors: fields, unmappedErrors } = fieldErrorsFrom(error, LOGIN_FIELDS)
      const hasFieldErrors = Object.keys(fields).length > 0

      setFieldErrors(fields)
      setOtherErrors(unmappedErrors)
      setFormError(summaryMessage(error, hasFieldErrors))
      setPassword('')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="auth">
      <div className="auth__card">
        <h1>Sign in</h1>
        <p className="auth__intro">Use the account you registered with.</p>

        <FormAlert message={formError} extra={otherErrors} />

        <form onSubmit={handleSubmit} noValidate>
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
            autoComplete="current-password"
            required
            error={fieldErrors.password}
          />

          <button type="submit" className="auth__submit" disabled={submitting}>
            {submitting ? 'Signing in…' : 'Sign in'}
          </button>
        </form>

        <p className="auth__switch">
          Need an account? <Link to={REGISTER_ROUTE}>Register</Link>
        </p>
      </div>
    </main>
  )
}
