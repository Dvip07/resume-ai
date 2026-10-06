import { stageLabel } from '../api/applications'

/**
 * Pipeline-stage badge for a job listing (Requirement 10.1).
 *
 * Accessibility, same rules as `ResumeStatusBadge`: the stage is carried by the
 * visible text, each stage gets a distinct glyph as well as a hue, and the
 * glyph is `aria-hidden` because the adjacent text already names the stage.
 */
const GLYPHS: Record<string, string> = {
  discovered: '•',
  enriching: '⟳',
  scored: '◆',
  tailoring: '⟳',
  tailored: '◆',
  applying: '⟳',
  applied: '✓',
  failed: '!',
  needs_review: '!',
  store_only: '▣',
}

export function PipelineStageBadge({ stage }: { stage: string | null | undefined }) {
  const known = stage != null && stage in GLYPHS
  const modifier = known ? stage : 'unknown'

  return (
    <span className={`status-badge status-badge--stage-${modifier}`}>
      <span aria-hidden="true" className="status-badge__glyph">
        {(stage != null ? GLYPHS[stage] : undefined) ?? '?'}
      </span>
      {stageLabel(stage)}
    </span>
  )
}
