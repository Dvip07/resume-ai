import { statusLabel, type Resume } from '../api/resumes'

/**
 * Parsing-status badge for a resume (Requirement 2.4).
 *
 * Accessibility: the status is carried by the visible text label, so colour is
 * never the only signal. Each state also gets a distinct glyph and border
 * treatment for users who can't distinguish the hues. The glyph is
 * `aria-hidden` because the adjacent text already names the state.
 */
const GLYPHS: Record<string, string> = {
  uploaded: '•',
  parsing: '⟳',
  parsed: '✓',
  failed: '!',
}

export function ResumeStatusBadge({ status }: { status: Resume['status'] }) {
  const known = status in GLYPHS
  const modifier = known ? status : 'unknown'

  return (
    <span className={`status-badge status-badge--${modifier}`}>
      <span aria-hidden="true" className="status-badge__glyph">
        {GLYPHS[status] ?? '?'}
      </span>
      {statusLabel(status)}
    </span>
  )
}
