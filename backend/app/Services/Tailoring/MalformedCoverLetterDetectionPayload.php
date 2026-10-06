<?php

namespace App\Services\Tailoring;

use RuntimeException;

/**
 * Internal signal: the cover-letter classifier returned decodable JSON that
 * does not answer the question — a `requirement` outside the three allowed
 * labels, or a confidence that is not a number.
 *
 * The same role, and the same reasons, as
 * {@see MalformedTailoringPayload} and
 * {@see \App\Services\Scoring\MalformedScoringPayload}: the call succeeded and
 * was billed, so this is not a
 * {@see \App\Exceptions\ModelRouterException}; and a JSON schema constrains
 * shape rather than sense, so an enum a provider silently downgraded still has
 * to be checked here.
 *
 * Unlike its two siblings, this one never escalates into a typed public
 * exception. It is caught inside
 * {@see CoverLetterRequirementDetector}, converted into one stricter-instruction
 * retry, and — if that also fails — into Requirement 7.2's default answer. See
 * that class's docblock for why a failed detection is a "no letter" rather than
 * a failed pipeline stage.
 */
class MalformedCoverLetterDetectionPayload extends RuntimeException
{
}
