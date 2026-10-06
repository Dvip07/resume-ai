<?php

namespace App\Services\Tailoring;

/**
 * A hiring contact a caller *knows*, for the recipient block of a cover letter.
 *
 * ## Why this is a class and not three nullable string parameters
 *
 * Because the type is the argument. Every field here is a fact about a real
 * person or a real address, and the single worst output this feature can produce
 * is an invented hiring manager's name — a letter opening "Dear Sarah Mitchell,"
 * at a company that employs nobody by that name is not a rough edge, it is a
 * lie in the user's own voice, sent under their name, and it discredits the
 * whole application in a way a clumsy paragraph never would.
 *
 * {@see CoverLetterTailoringService} therefore gives the model no field for any
 * of this (see that class's docblock), and the only way these values enter a
 * letter is through an instance of this class, constructed by a caller that has
 * a source for them. Requiring the caller to name the type is what makes
 * "supplied from a real source" a visible decision at the call site rather than
 * three optional strings that a future refactor might helpfully default from
 * somewhere.
 *
 * When no instance is passed — the normal case, since postings very rarely name
 * a contact — every recipient field is absent and the template's own "Dear
 * Hiring Manager," fallback applies. That is the *correct* output, not a
 * degraded one: it is what a careful human writes when they do not know who will
 * read the letter.
 *
 * Note what is not here: the company name and the job title. Those come from
 * the {@see \App\Models\JobListing}, which is a real source by construction, so
 * a caller never has to supply them.
 */
final class CoverLetterRecipient
{
    /**
     * @param  ?string  $name  the person the letter is addressed to; also what
     *                         turns "Dear Hiring Manager," into "Dear <name>,"
     * @param  ?string  $title  their role, e.g. "Engineering Manager"
     * @param  array<int, string>  $addressLines  the company's postal address, one
     *                                            element per rendered line
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $title = null,
        public readonly array $addressLines = [],
    ) {}

    /** The name, trimmed, or null when it is absent or blank. */
    public function name(): ?string
    {
        return $this->clean($this->name);
    }

    /** The title, trimmed, or null when it is absent or blank. */
    public function title(): ?string
    {
        return $this->clean($this->title);
    }

    /**
     * Address lines, trimmed, with blanks dropped.
     *
     * @return array<int, string>
     */
    public function addressLines(): array
    {
        $lines = [];

        foreach ($this->addressLines as $line) {
            $clean = $this->clean(is_string($line) ? $line : null);

            if ($clean !== null) {
                $lines[] = $clean;
            }
        }

        return $lines;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
