import { useId, type InputHTMLAttributes } from 'react'

interface TextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  label: string
  /** Server- or client-side message for this field; renders below the input. */
  error?: string
  /** Optional static hint, e.g. password rules. */
  hint?: string
}

/**
 * Labelled text input with accessible error wiring:
 * - `<label for>` ↔ `id` so the label is always programmatically associated
 * - `aria-invalid` marks the field as errored for assistive tech
 * - `aria-describedby` points at the hint and/or error text
 * - the error node itself is not a live region; the form-level alert handles
 *   announcement so a screen reader hears one message, not five
 */
export function TextField({ label, error, hint, required, ...inputProps }: TextFieldProps) {
  const id = useId()
  const errorId = `${id}-error`
  const hintId = `${id}-hint`

  const describedBy = [hint ? hintId : null, error ? errorId : null]
    .filter(Boolean)
    .join(' ')

  return (
    <div className="field">
      <label htmlFor={id}>
        {label}
        {required ? (
          <span aria-hidden="true" className="field__required">
            {' '}
            *
          </span>
        ) : null}
      </label>

      <input
        {...inputProps}
        id={id}
        required={required}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy || undefined}
        className={error ? 'field__input field__input--error' : 'field__input'}
      />

      {hint ? (
        <p className="field__hint" id={hintId}>
          {hint}
        </p>
      ) : null}

      {error ? (
        <p className="field__error" id={errorId}>
          {error}
        </p>
      ) : null}
    </div>
  )
}
