<?php

namespace App\Services\Tailoring;

use RuntimeException;

/**
 * Internal signal: the tailoring model returned decodable JSON that does not
 * say what the resume template needs it to say — a blank summary, no bullets
 * for any role the candidate actually has, or LaTeX smuggled into a field that
 * was asked for as plain prose.
 *
 * Kept separate from {@see \App\Exceptions\ModelRouterException} because it is
 * not a transport or provider problem: the call succeeded and was billed. It is
 * also deliberately *not* the exception the pipeline sees — it is caught inside
 * {@see ResumeTailoringService}, which converts it into one stricter-instruction
 * retry and, only if that also fails, into a
 * {@see \App\Exceptions\ResumeTailoringException}.
 *
 * Shared with {@see CoverLetterTailoringService}, which raises it for its own
 * unusable payloads (no paragraphs, a one-paragraph "letter", Markdown or LaTeX
 * in the prose). Sharing is safe here in a way that sharing the *public*
 * exception is not: this type never leaves either service, so its only job is to
 * distinguish "billed call, unusable content" from a transport failure — a
 * question with the same answer for both documents. The typed exception the
 * caller catches is per-document, because that is the one a caller branches on
 * (see {@see \App\Exceptions\CoverLetterTailoringException}).
 *
 * Exact counterpart of {@see \App\Services\Scoring\MalformedScoringPayload},
 * and for the same reason: a JSON schema constrains shape, not sense. No schema
 * can require a non-blank summary, require bullets to reference a role that
 * exists, or forbid a `\textbf{}` inside a string — and a model whose provider
 * silently downgraded `response_format` never saw the schema at all.
 */
class MalformedTailoringPayload extends RuntimeException
{
}
