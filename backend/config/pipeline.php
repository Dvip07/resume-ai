<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-apply defaults (Requirements 9.1, 9.3, 9.7)
    |--------------------------------------------------------------------------
    |
    | The system-wide fallbacks used when a user has no `user_automation_settings`
    | row, read by App\Models\UserAutomationSetting::forUser(). Per-user rows
    | override every value here; nothing in the model is a literal, so an
    | operator can retune the platform default without a code change (same rule
    | as config/scoring.php).
    |
    | The two consent flags default to false on purpose. Submitting an
    | application in someone's name is an explicit act, so an unconfigured user
    | is a user who has not agreed to anything — and no env value should be able
    | to flip that for everybody at once, which is why they are not env-driven.
    |
    */

    'auto_apply_enabled' => false,

    'linkedin_auto_apply_opt_in' => false,

    /*
    | There is deliberately no `auto_apply_star_threshold` here:
    | config/scoring.php already owns that number (it is what prompt 2 is told
    | "auto_apply" means), and a second copy would let the model be calibrated
    | to a different bar than the pipeline enforces. UserAutomationSetting
    | reads it from `scoring` directly.
    */

    /*
    | Requirement 9.7: applications submitted per user per day, in total and on
    | LinkedIn specifically. The LinkedIn cap is much lower because Easy Apply
    | automation is the highest-risk path (Requirement 9.3) and the account at
    | stake is the user's.
    */
    'daily_apply_cap' => (int) env('PIPELINE_DAILY_APPLY_CAP', 20),

    'linkedin_daily_apply_cap' => (int) env('PIPELINE_LINKEDIN_DAILY_APPLY_CAP', 5),

    /*
    |--------------------------------------------------------------------------
    | Scheduled discovery (Requirements 3.1, 11.1)
    |--------------------------------------------------------------------------
    |
    | Read by App\Console\Kernel::schedule(). The `jobs:discover` command only
    | dispatches one DiscoverJobsForUser per candidate user, so the cadence
    | here governs how often the fan-out happens, not how much work each run
    | does — the per-source daily budgets (ProviderDailyLimiter) are what bound
    | API spend, which is why hourly is a safe default.
    |
    | A cron expression rather than a named frequency, because the useful knob
    | differs per deployment: a demo box wants a run every six hours, a host
    | whose provider quotas reset at midnight UTC wants discovery to start just
    | after. One expression covers all of those without a code change.
    |
    | `enabled` exists for deployments that drive discovery themselves (a manual
    | `jobs:discover`, or an external orchestrator) and still run `schedule:run`
    | for everything else. Turning it off leaves the command available; it just
    | stops firing on its own.
    |
    */

    'discovery' => [

        'enabled' => (bool) env('PIPELINE_DISCOVERY_ENABLED', true),

        'cron' => env('PIPELINE_DISCOVERY_CRON', '0 * * * *'),

        /*
        | The timezone the cron expression is read in. Defaults to the app
        | timezone, so `0 9 * * *` means what the operator who wrote it meant
        | without converting by hand.
        */
        'timezone' => env('PIPELINE_DISCOVERY_TIMEZONE', env('APP_TIMEZONE', 'UTC')),

    ],

    /*
    |--------------------------------------------------------------------------
    | Tailoring compile retries (Requirement 6.4)
    |--------------------------------------------------------------------------
    |
    | Read by App\Services\Tailoring\ResumeTailoringPipeline. How many times a
    | *failed LaTeX compile* may send the tailoring prompt back to the model
    | with the engine's error attached. 2 retries means at most three renders
    | for one tailoring run; 0 disables the loop and turns the first compile
    | failure into a review item.
    |
    | Not the same budget as `latex.tailoring.max_attempts`, and the two are
    | spent in different places for different reasons:
    |
    |   latex.tailoring.max_attempts  the model's *response* was unusable —
    |                                 unparseable JSON, blank summary, LaTeX
    |                                 where prose was asked for. Spent inside
    |                                 ResumeTailoringService, before any engine
    |                                 runs, and invisible to the pipeline.
    |   pipeline.latex_retry_max      the response was fine and the *document*
    |                                 it produced would not compile. Spent by
    |                                 the pipeline, and each retry re-enters
    |                                 tailoring with a fresh response budget.
    |
    | So one tailoring run can cost up to (latex_retry_max + 1) *
    | latex.tailoring.max_attempts model calls in the worst case, which is why
    | neither number is large.
    |
    | Kept here rather than in config/latex.php because it governs the pipeline
    | step, not the compiler: the value the compiler needs (timeout, binary,
    | engine) is per-host, whereas this is a per-run cost/quality tradeoff
    | alongside the other pipeline bounds above.
    */
    'latex_retry_max' => (int) env('PIPELINE_LATEX_RETRY_MAX', 2),

    /*
    |--------------------------------------------------------------------------
    | Fabrication guard (Requirements 6.6, 9.4)
    |--------------------------------------------------------------------------
    |
    | Read by App\Services\Tailoring\FabricationGuard, which diffs the strings
    | the model wrote (summary, headline, bullets) against the facts the profile
    | supplied, and reports anything the profile does not support so the caller
    | can move the listing to `needs_review` instead of auto-applying with it.
    |
    | Everything here exists to keep that guard from crying wolf. A guard that
    | flags a legitimate bullet is worse than no guard: the review queue fills
    | with noise and the real finding is the one nobody reads. So the detectors
    | are deliberately narrow, and how narrow is tunable without a code change.
    |
    */

    'fabrication' => [

        /*
        | Words that make a preceding capitalised phrase an organisation rather
        | than a product, a team or a sentence opener. This list is the whole
        | reason employer detection is safe to run at all: "Reduced Globex Inc
        | onboarding time" names a company, whereas "Reduced Kubernetes rollout
        | time" names a tool, and only a legal/organisational suffix tells the
        | two apart without a knowledge base. Matching on "any capitalised
        | word" instead would flag every technology in every bullet.
        */
        'employer_suffixes' => [
            'Inc', 'Inc.', 'LLC', 'L.L.C.', 'Ltd', 'Ltd.', 'Limited', 'LLP',
            'Corp', 'Corp.', 'Corporation', 'Co', 'Co.', 'Company',
            'PLC', 'GmbH', 'AG', 'NV', 'BV', 'SA', 'SAS', 'AB', 'AS', 'Oy',
            'Pty', 'Pvt', 'Holdings', 'Group', 'Partners', 'Ventures',
        ],

        /*
        | Words that make a following four-digit number a *date* rather than a
        | quantity. "grew revenue 40% in 2023" states a year; "scaled to 2000
        | tenants" states a count, and a bare-number detector would flag it.
        | A year range ("2019-2021", "2019 to present") is recognised without a
        | cue, since the range itself is unambiguous.
        */
        'date_cues' => [
            'in', 'since', 'from', 'during', 'until', 'through', 'throughout',
            'by', 'before', 'after', 'until', 'year', 'fy', 'cy',
            'q1', 'q2', 'q3', 'q4', 'h1', 'h2',
            'january', 'february', 'march', 'april', 'may', 'june', 'july',
            'august', 'september', 'october', 'november', 'december',
            'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept',
            'oct', 'nov', 'dec',
        ],

        /*
        | The narrowest year a four-digit number can be and still be read as a
        | date. Below this it is a quantity that happened to sit after a cue
        | ("cut cost by 1200 dollars"), and no resume in this system describes
        | work done before it.
        */
        'min_plausible_year' => (int) env('PIPELINE_FABRICATION_MIN_YEAR', 1950),

        /*
        | Years past today that still count as a date rather than a typo. One
        | covers a bullet written in December about work landing next quarter.
        */
        'future_year_slack_years' => (int) env('PIPELINE_FABRICATION_FUTURE_SLACK', 1),

        /*
        | Slack applied to both ends of the window of years the profile
        | supports. The profile's date text is coarse by nature — "2019-2021"
        | may really be December 2018 to January 2022, and a month-count
        | duration ("30 months") pins no year at all — so a bullet naming a
        | year one off the stated boundary is a rounding artefact, not a
        | fabrication. Two years outside it is a different claim.
        */
        'date_window_slack_years' => (int) env('PIPELINE_FABRICATION_DATE_SLACK', 1),

        /*
        | Cap on findings carried in one report. The report is headed for a log
        | line and for `tailored_documents.fabrication_flags`; a document that
        | trips fifty of these needs a human either way, and the first handful
        | tell them the same story as all fifty.
        */
        'max_findings' => (int) env('PIPELINE_FABRICATION_MAX_FINDINGS', 25),

    ],

];
