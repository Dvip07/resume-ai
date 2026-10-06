<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Two-prompt scoring pipeline (Requirement 5)
    |--------------------------------------------------------------------------
    |
    | Read by App\Services\Scoring\JobScoringService. Nothing here is duplicated
    | as a literal in the service, so prompt budgets and the retry allowance can
    | be retuned without a code change (same rule as the openrouter block in
    | config/services.php).
    |
    */

    /*
    | Attempts allowed per prompt. Requirement 5.5 asks for exactly one retry
    | with a stricter formatting instruction after a parse failure, so 2 is the
    | intended value; it is configurable only so an operator can disable the
    | retry (1) if a provider starts charging for obviously-doomed calls.
    | Values below 1 are clamped to 1.
    |
    | Note this is *on top of* ModelRouterService's own same-tier model
    | fallback (Requirement 4.5): by the time a failure reaches the scoring
    | service, every model in the tier has already been tried with the original
    | wording. The retry here changes the prompt, not the model.
    */
    'max_attempts' => (int) env('SCORING_MAX_ATTEMPTS', 2),

    /*
    | Characters of job-description text sent to prompt 1. Postings are
    | occasionally padded with boilerplate ("about us", benefits, legal
    | notices) that runs to tens of thousands of characters; the requirements
    | and responsibilities that scoring depends on are near the top. Truncating
    | keeps a single listing from dominating the token bill.
    */
    'max_description_chars' => (int) env('SCORING_MAX_DESCRIPTION_CHARS', 20000),

    /*
    | Characters of the serialized candidate profile (skills, experience,
    | education, keywords) sent to prompt 1.
    */
    'max_profile_chars' => (int) env('SCORING_MAX_PROFILE_CHARS', 12000),

    /*
    | Characters of raw resume text appended to the profile summary. The
    | structured profile is the primary signal; the raw text is there to catch
    | detail the parser flattened, so it gets a smaller budget.
    */
    'max_resume_text_chars' => (int) env('SCORING_MAX_RESUME_TEXT_CHARS', 6000),

    /*
    | Inclusive bounds of the star rating produced by prompt 2 (Requirement
    | 5.1). The JSON schema and the post-parse validation both read these, so
    | the model is asked for, and held to, the same range.
    */
    'stars' => [
        'min' => (int) env('SCORING_STARS_MIN', 1),
        'max' => (int) env('SCORING_STARS_MAX', 5),
    ],

    /*
    | The rating at which a fit is strong enough to apply without a human
    | reading it first (Requirement 5.2's default of 4). The scoring service
    | uses it for one purpose only — telling prompt 2 what "auto_apply" means,
    | so the model's recommendation is calibrated to the same number the
    | pipeline will judge it by.
    |
    | This is the system-wide default. The branching decision, and the per-user
    | `auto_apply_star_threshold` that overrides this, belong to the queued
    | scoring job and `user_automation_settings` (tasks 10.3 and 10.5) — a
    | recommendation from a model never bypasses either.
    */
    'auto_apply_star_threshold' => (int) env('SCORING_AUTO_APPLY_STAR_THRESHOLD', 4),

];
