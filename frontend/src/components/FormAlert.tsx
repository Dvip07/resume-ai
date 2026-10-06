/**
 * Form-level error region for the auth screens.
 *
 * Always rendered (even when empty) so it exists in the accessibility tree
 * before the first error arrives — a `role="alert"` node that is *mounted* on
 * error is announced inconsistently across screen readers, whereas an existing
 * live region reliably announces text inserted into it.
 */
export function FormAlert({ message, extra }: { message?: string | null; extra?: string[] }) {
  const extras = extra ?? []

  return (
    <div className="form-alert" role="alert" aria-live="assertive">
      {message ? <p className="form-alert__message">{message}</p> : null}
      {extras.length > 0 ? (
        <ul className="form-alert__list">
          {extras.map((entry) => (
            <li key={entry}>{entry}</li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}
