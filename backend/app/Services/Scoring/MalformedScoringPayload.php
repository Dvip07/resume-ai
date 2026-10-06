<?php

namespace App\Services\Scoring;

use RuntimeException;

/**
 * Internal signal: a model returned decodable JSON that does not say what a
 * scoring prompt needs it to say — a missing `stars`, a rating of 9, an empty
 * rationale, a `recommendedAction` outside {@see \App\Enums\RecommendedAction}.
 *
 * Kept separate from {@see \App\Exceptions\ModelRouterException} because it is
 * not a transport or provider problem: the call succeeded and was billed. It is
 * also deliberately *not* the exception the pipeline sees — it is caught inside
 * {@see JobScoringService}, which converts it into the stricter-instruction
 * retry of Requirement 5.5 and, only if that also fails, into a
 * {@see \App\Exceptions\JobScoringException}.
 *
 * Why validate at all when the router requests strict structured output: the
 * schema constrains shape, not sense. A schema cannot require a non-blank
 * rationale, and a model that ignores `response_format` (or a provider that
 * silently downgrades it) still returns something that decodes. Treating those
 * as parse failures is what makes Requirement 5.5's "fails to return parseable
 * structured output" cover the cases that actually occur.
 */
class MalformedScoringPayload extends RuntimeException
{
}
