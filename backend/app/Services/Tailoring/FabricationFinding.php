<?php

namespace App\Services\Tailoring;

use App\Enums\FabricationFindingType;

/**
 * One unsupported claim in a tailored document: what kind, which token, and
 * where it was found (Requirement 6.6).
 *
 * The `where` is not decoration. A finding a human cannot locate is a finding
 * they cannot judge, and "the document mentions Globex" sends a reviewer reading
 * a whole page to decide whether the guard is right. `bullet 2 of role 1
 * (Analytical & Co)` sends them to one line.
 *
 * Immutable and Eloquent-free, like the rest of `Services\Tailoring`: the guard
 * produces these before anything is persisted, and the caller of task 11.7
 * decides whether they mean `needs_review`.
 */
final class FabricationFinding
{
    /**
     * @param  string  $token  the offending text, verbatim as it appeared — the
     *                         employer phrase, the year, or the profile fact that went
     *                         missing. Kept unnormalized so a reviewer can search the
     *                         document for it.
     * @param  string  $location  human-addressable position: `summary`, `headline`,
     *                            `bullet 2 of role 1 (Acme Corp)`, `rendered document`
     * @param  string  $detail  one sentence a reviewer can act on without reading this
     *                          class
     */
    public function __construct(
        public readonly FabricationFindingType $type,
        public readonly string $token,
        public readonly string $location,
        public readonly string $detail,
    ) {}

    public static function unknownEmployer(string $token, string $location, string $detail): self
    {
        return new self(FabricationFindingType::UnknownEmployer, $token, $location, $detail);
    }

    public static function unsupportedDate(string $token, string $location, string $detail): self
    {
        return new self(FabricationFindingType::UnsupportedDate, $token, $location, $detail);
    }

    public static function missingFact(string $token, string $location, string $detail): self
    {
        return new self(FabricationFindingType::MissingFact, $token, $location, $detail);
    }

    /**
     * The shape stored in `tailored_documents.fabrication_flags` and shown in a
     * review item.
     *
     * @return array{type: string, token: string, location: string, detail: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'token' => $this->token,
            'location' => $this->location,
            'detail' => $this->detail,
        ];
    }

    /** One line, for a log message or a review list. */
    public function __toString(): string
    {
        return sprintf('[%s] "%s" in %s', $this->type->value, $this->token, $this->location);
    }
}
