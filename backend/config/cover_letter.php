<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cover-letter requirement detection (Requirements 7.1, 7.2)
    |--------------------------------------------------------------------------
    |
    | Read by App\Services\Tailoring\CoverLetterRequirementDetector, which
    | answers one question about a posting: does it ask for a cover letter at
    | all? Requirement 7.2 makes that question worth answering carefully — a
    | posting that never mentions one must not cost a tailoring call, and a
    | false positive costs both that call and a document nobody asked for.
    |
    | Same rule as config/scoring.php and the `tailoring` block of
    | config/latex.php: every phrase list, window and threshold lives here, so
    | the wording an operator observes in real postings can be tuned without a
    | code change (Requirement 4.2's spirit applied to a keyword pass).
    |
    | The lists are read as case-insensitive plain substrings against a
    | normalized copy of the description (lowercased, hyphens and curly
    | apostrophes folded, whitespace collapsed), so "Cover-Letter Required"
    | and "cover letter required" match the same entry. They are not regular
    | expressions: an operator adding a phrase from a posting they just read
    | should not have to escape anything.
    |
    */

    'detection' => [

        /*
        | The terms that count as *mentioning* a cover letter at all. Nothing
        | else in this block is consulted unless one of these appears, and a
        | posting containing none of them is answered "not requested" without
        | a single model call — the cheap, common case Requirement 7.2 exists
        | to protect.
        |
        | "letter of motivation" / "letter of interest" are included because
        | European and academic postings routinely ask for exactly that and
        | mean a cover letter; the tailored document is the same artifact.
        */
        'mention_terms' => [
            'cover letter',
            'cover letters',
            'covering letter',
            'covering letters',
            'motivation letter',
            'letter of motivation',
            'letter of interest',
            'letter of application',
        ],

        /*
        | How much text around a mention is read to decide what the mention
        | means. Cues are looked for in this window rather than in the whole
        | posting, because "optional" appearing 4,000 characters away in the
        | benefits section says nothing about the cover letter.
        |
        | Asymmetric on purpose: the qualifier usually *follows* the noun
        | ("a cover letter is not required"), but plenty of postings put it in
        | front ("we do not require a cover letter"), so both directions get a
        | generous window. Widening these trades precision for recall.
        */
        'window_chars_before' => (int) env('COVER_LETTER_WINDOW_BEFORE', 120),
        'window_chars_after' => (int) env('COVER_LETTER_WINDOW_AFTER', 160),

        /*
        | Cue classes, evaluated per mention in exactly this order. The order
        | is the substance of the detector, so it is documented rather than
        | left implicit in the code:
        |
        |  1. `negation_overrides` — a negation that actually *demands* a
        |     letter. "Applications without a cover letter will not be
        |     considered" contains "without a cover letter" and means the
        |     opposite of it. Checked first, because every later class would
        |     read this sentence backwards.
        |  2. `optionality` — the letter is wanted but not mandatory. Checked
        |     before negation so that "not required, but strongly encouraged"
        |     lands on optional rather than on the "not required" inside it.
        |  3. `negation` — the letter is explicitly not wanted.
        |  4. `request` — the letter is asked for or mandated.
        |
        | A mention matching none of the four is *ambiguous*, and ambiguity is
        | the only thing that spends a model call.
        */
        'negation_overrides' => [
            'will not be considered',
            'not be considered',
            'will not be reviewed',
            'will be rejected',
            'considered incomplete',
            'incomplete application',
            'incomplete without',
            'is required to be considered',
        ],

        'optionality' => [
            'optional',
            'optionally',
            'not required but',
            'not required, but',
            'not mandatory but',
            'not mandatory, but',
            'not necessary but',
            'not necessary, but',
            'encouraged',
            'appreciated',
            'welcomed',
            'are welcome',
            'is welcome',
            'if you wish',
            'if you like',
            'if you would like',
            'if you choose',
            'if you want',
            'feel free',
            'may include',
            'may submit',
            'may attach',
            'may add',
            'can include',
            'can submit',
            'can attach',
            'nice to have',
            'a plus',
            'bonus',
        ],

        'negation' => [
            'no cover letter',
            'no covering letter',
            'no motivation letter',
            'not required',
            'not mandatory',
            'not necessary',
            'not needed',
            'no need',
            'do not require',
            'does not require',
            'dont require',
            'do not ask',
            'does not ask',
            'dont ask',
            'do not include',
            'do not send',
            'do not submit',
            'do not attach',
            'do not upload',
            'dont include',
            'dont send',
            'dont submit',
            'not accepted',
            'not accepting',
            'do not accept',
            'dont accept',
            'without a cover letter',
            'without cover letter',
            'please refrain',
            'omit',
            'skip the',
            'unnecessary',
        ],

        'request' => [
            'required',
            'require a',
            'requires a',
            'must include',
            'must submit',
            'must attach',
            'must provide',
            'must send',
            'must upload',
            'please include',
            'please submit',
            'please attach',
            'please send',
            'please provide',
            'please upload',
            'please write',
            'submit a',
            'submit your',
            'include a',
            'include your',
            'attach a',
            'attach your',
            'upload a',
            'upload your',
            'provide a',
            'provide your',
            'send a',
            'send your',
            'write a',
            'along with',
            'accompanied by',
            'together with',
            'as well as a',
            'and a cover letter',
            'and cover letter',
            'resume and',
            'cv and',
            'applications must',
            'tell us why',
            'explaining why',
        ],

        /*
        | Confidence recorded for a decision the keyword pass reached on its
        | own. Deliberately below 1.0: a phrase list is a good heuristic and
        | not a proof, and the caller (task 12.3) logs this number, so it
        | should not read as certainty. Kept above `min_model_confidence` so a
        | keyword decision is never weaker than an accepted model one.
        */
        'keyword_confidence' => (float) env('COVER_LETTER_KEYWORD_CONFIDENCE', 0.9),

        /*
        | Confidence recorded when the posting never mentions a cover letter.
        | Higher than a cue-based decision because it rests on the absence of
        | any evidence rather than on the reading of some — there is no phrase
        | to have misread.
        */
        'absent_confidence' => (float) env('COVER_LETTER_ABSENT_CONFIDENCE', 0.95),

        /*
        | How sure the model has to be before its "required"/"optional" answer
        | is honoured. Below this the answer is recorded but downgraded to
        | "not requested", because Requirement 7.2 makes the two errors
        | asymmetric: a missed optional letter costs an opportunity, an
        | invented one costs a tailoring call plus a document in the user's
        | application that nobody asked for.
        |
        | A low-confidence "not requested" needs no threshold — it agrees with
        | the default.
        */
        'min_model_confidence' => (float) env('COVER_LETTER_MIN_MODEL_CONFIDENCE', 0.6),

        /*
        | Attempts allowed for the classification prompt: one normal, then one
        | carrying a stricter formatting instruction. The same contract as
        | `scoring.max_attempts` and `latex.tailoring.max_attempts`, and for
        | the same reason. Clamped to at least 1; set to 1 to disable the
        | retry.
        */
        'max_attempts' => (int) env('COVER_LETTER_DETECTION_MAX_ATTEMPTS', 2),

        /*
        | The tier the classification runs in, pinned rather than derived from
        | salary or complexity.
        |
        | This is the one routing decision in the pipeline where the stakes
        | argument behind Requirements 4.3/4.4 does not apply: the task is a
        | three-way label on a sentence, the answer is schema-constrained, and
        | a premium model is not measurably better at reading "cover letter
        | optional" than a cheap one. Escalating a 200-token classification
        | because the *role* pays well would spend premium tokens on the
        | cheapest question in the system — and it would spend them precisely
        | on the postings whose tailoring calls are already the expensive
        | ones.
        |
        | Set to null to fall back to ordinary tier selection.
        */
        'tier_override' => env('COVER_LETTER_DETECTION_TIER', 'cheap'),

        /*
        | Characters of mention-window excerpts sent to the model. Only text
        | near a cover-letter mention can bear on the question, so the prompt
        | carries the windows rather than the posting — a 30,000-character JD
        | costs the same classification either way, and the excerpts keep the
        | model from being distracted by the benefits section.
        */
        'max_excerpt_chars' => (int) env('COVER_LETTER_MAX_EXCERPT_CHARS', 2000),

    ],

    /*
    |--------------------------------------------------------------------------
    | Cover-letter generation (Requirement 7.1)
    |--------------------------------------------------------------------------
    |
    | Read by App\Services\Tailoring\CoverLetterTailoringService — the LLM half
    | of cover-letter tailoring, which produces the content array
    | `LatexRenderService::renderCoverLetter()` consumes.
    |
    | A sibling block rather than an extension of `detection`, and deliberately
    | so: the two share a config file because they are about the same feature,
    | but they share not one setting. `detection.max_attempts` bounds a
    | three-way label on a sentence and `generation.max_attempts` bounds a page
    | of prose; `detection.tier_override` pins the cheap tier for a reason —
    | "a premium model is no better at reading 'cover letter optional'" — that
    | is the exact opposite of true for writing the letter. Folding them
    | together would mean one env var moving both, and the first operator to
    | widen the detection retry would silently double the generation spend.
    |
    | The budgets split the same way as `latex.tailoring`'s. The *input*
    | budgets cap the token bill of one run. The *output* budgets cap the
    | physical page — and here that is the whole point: a cover letter is one
    | page, a model asked for "compelling paragraphs" will return seven, and no
    | prompt reliably stops it. Enforcing the bound in code means a model that
    | ignores the instruction still yields a letter that fits.
    |
    */

    'generation' => [

        /*
        | Attempts allowed for the generation prompt: one normal, then one
        | carrying a stricter formatting instruction naming what went wrong.
        | The same contract as `latex.tailoring.max_attempts` and
        | `scoring.max_attempts`. Clamped to at least 1; set to 1 to disable
        | the retry.
        */
        'max_attempts' => (int) env('COVER_LETTER_MAX_ATTEMPTS', 2),

        /*
        | Characters of job-description text the prompt sees. Larger than the
        | resume budget's *purpose* would suggest but the same number: a letter
        | argues for fit in prose, so the posting's tone and its stated
        | motivations matter as much as its keyword list.
        */
        'max_description_chars' => (int) env('COVER_LETTER_MAX_DESCRIPTION_CHARS', 12000),

        /*
        | Characters of the serialized candidate summary (roles, achievements,
        | skills, education) sent with the prompt.
        */
        'max_profile_chars' => (int) env('COVER_LETTER_MAX_PROFILE_CHARS', 12000),

        /*
        | Roles offered to the prompt, most recent first. A letter draws on the
        | last two or three jobs; older ones are prompt tokens spent on
        | material that will not appear in the output.
        */
        'max_experience_entries' => (int) env('COVER_LETTER_MAX_EXPERIENCE_ENTRIES', 4),

        /*
        | The body of the letter. `max_paragraphs` and `max_paragraph_chars`
        | together are the one-page guarantee: at the template's 1in margins
        | and 0.75em \parskip, 4 x 900 characters plus the sender, recipient,
        | subject and signature blocks fills a page and does not overflow it.
        |
        | `min_paragraphs` is a *quality* floor rather than a layout one. A
        | single-paragraph response is not a short letter, it is a model that
        | answered a different question — an opening line with no argument and
        | no close — and it is worth one stricter retry rather than shipping.
        */
        'min_paragraphs' => (int) env('COVER_LETTER_MIN_PARAGRAPHS', 2),
        'max_paragraphs' => (int) env('COVER_LETTER_MAX_PARAGRAPHS', 4),
        'max_paragraph_chars' => (int) env('COVER_LETTER_MAX_PARAGRAPH_CHARS', 900),

        /*
        | Length of the sign-off line ("Sincerely,"). Short by design: this is
        | a closing, and a model that returns a sentence here has misread the
        | field. There is no default *value* to configure — when the model
        | offers no closing the template's own "Sincerely," fallback applies,
        | which is where that degradation rule belongs (see the template's
        | header comment).
        */
        'max_closing_chars' => (int) env('COVER_LETTER_MAX_CLOSING_CHARS', 40),

        /*
        | How the letter's date line is formatted. A display format, applied to
        | the request's `now()` — the template does no date formatting at all,
        | on purpose, so the choice of locale wording lives here.
        |
        | The default is the unambiguous long form. Numeric formats are a bad
        | idea in a document read on both sides of the Atlantic: 03/09/2025 is
        | two different days depending on the reader.
        */
        'date_format' => env('COVER_LETTER_DATE_FORMAT', 'F j, Y'),

        /*
        | The template key handed to `renderCoverLetter()` when a caller does
        | not choose one. Looked up in that method's cover-letter-only
        | whitelist, so a resume key here is rejected rather than silently
        | rendering the wrong document.
        */
        'default_template_key' => env('COVER_LETTER_TEMPLATE_KEY', 'default'),

    ],

];
