<?php

namespace App\Enums;

/**
 * Which kind of document a `tailored_documents` row holds (design.md
 * "New tables", Requirement 6.5).
 *
 * The DB column is a portable `string`, so this enum is the single place the
 * allowed values live; TailoredDocument casts `type` to it.
 *
 * The two kinds share the row shape (template, S3 paths, generation model) but
 * are produced by different prompts, rendered from different LaTeX templates,
 * and hang off `applications` through different columns — which is what
 * `applicationForeignKey()` resolves.
 */
enum TailoredDocumentType: string
{
    /** A resume rewritten against one listing's description. */
    case Resume = 'resume';

    /** A cover letter written for the same listing. */
    case CoverLetter = 'cover_letter';

    /**
     * The `applications` column that points at a document of this kind, so a
     * caller holding a document can link it without branching on the type
     * itself. `tailored_resume_id` is the singular name the rename migration
     * settled on (see
     * 2026_09_17_115133_rename_tailored_resumes_to_tailored_documents_table);
     * `tailored_cover_letter_id` is its sibling.
     */
    public function applicationForeignKey(): string
    {
        return match ($this) {
            self::Resume => 'tailored_resume_id',
            self::CoverLetter => 'tailored_cover_letter_id',
        };
    }
}
