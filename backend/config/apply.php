<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automated application submission (Requirements 9.2, 9.5)
    |--------------------------------------------------------------------------
    |
    | Adapters compile a declarative step script for the automation worker (see
    | `App\Services\Apply\ApplyStepScript`); the worker is a dumb executor and
    | holds no knowledge of any ATS. That leaves selectors as the one part of an
    | adapter guaranteed to rot — an ATS ships a markup change and a selector
    | that was right yesterday times out today.
    |
    | So selectors live here rather than in the adapter class. A production fix
    | is then a config/env change, not a code deploy, and every adapter
    | (Greenhouse here, Lever/Workday next) follows the same shape: hosts,
    | selectors, standard screening questions, confirmation markers, timeouts.
    |
    */

    /*
    | Disk the tailored resume/cover-letter PDFs were written to. Tailored
    | artefacts always go to `s3` (see `TailoredDocumentStorage::DISK`), so this
    | deliberately does not follow `filesystems.resume_disk` — an apply run must
    | sign the object where it actually is.
    */
    'document_disk' => env('APPLY_DOCUMENT_DISK', 's3'),

    /*
    | Minutes. The worker downloads each document from a signed URL, so the URL
    | only has to outlive one apply run — far shorter than the TTL the frontend
    | uses for a human clicking a link.
    */
    'document_url_ttl_minutes' => (int) env('APPLY_DOCUMENT_URL_TTL_MINUTES', 10),

    /*
    | Screenshots are the audit trail for Req 9.5 and are kept with the rest of
    | the user's artefacts, under the same `users/{user_id}/` prefix that
    | `S3StorageService::deleteForOwner()` and any bucket lifecycle rule rely on.
    */
    'screenshot_disk' => env('APPLY_SCREENSHOT_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | CAPTCHA / login-wall / bot-detection (Requirement 9.6)
    |--------------------------------------------------------------------------
    |
    | A wall is not a bug: no number of retries clears a bot check or a sign-in
    | gate, so a run that ends against one is handed to a human
    | (`needs_review`) with its screenshots, and is never retried and never
    | bypassed. See `AbstractApplyAdapter::blockedDetection()`.
    |
    | These are the ATS-independent markers, merged with whatever the adapter's
    | own block adds, so there is one list to maintain instead of three
    | divergent ones. The title scope additionally reuses
    | `enrichment.blocked_title_markers` — the generalized "Access Denied"
    | check the scraper already uses (see `config/enrichment.php` and
    | `JobEnrichmentService::blockedMarkerIn()`), which is the same question
    | asked of the same kind of page.
    |
    | Scope matters, and is the whole reason for the three lists:
    |
    | - `*_markers` are matched against text the worker *read back* from the
    |   page (`readText` on `body`, plus the confirmation element) — never the
    |   raw HTML, because every other Greenhouse and Lever board carries a
    |   "protected by reCAPTCHA" script tag and a footer notice while remaining
    |   perfectly applicable.
    | - `*_title_markers` are matched against the page `<title>` only, which is
    |   where a challenge page names itself and where a brand name ("reCAPTCHA")
    |   means a challenge rather than a footnote.
    | - `*_url_markers` are matched against the final URL, the strongest signal
    |   of all: the browser was redirected somewhere that is not the form.
    |
    */
    'blocked' => [

        /*
        | Phrases that only appear when a challenge is actually being put to
        | the visitor. Deliberately excludes bare vendor names — see the title
        | list for those.
        */
        'captcha_markers' => [
            'are you a robot',
            'are you a human',
            'verify you are human',
            'verify that you are human',
            'verify you are a human',
            "i'm not a robot",
            'i am not a robot',
            'complete the captcha',
            'complete the security check',
            'checking your browser',
            'enable javascript and cookies to continue',
            'unusual traffic from your',
            'automated traffic',
        ],

        'captcha_title_markers' => [
            'recaptcha',
            'hcaptcha',
            'turnstile',
            'bot detection',
            'robot check',
        ],

        'captcha_url_markers' => [
            '/captcha',
            '/challenge',
            '__cf_chl',
            'distil_r_captcha',
            'px-captcha',
        ],

        /*
        | HTTP status of the page the worker landed on. A 403/429 against an
        | application form is a bot wall in all but name; a 401 is the sign-in
        | version of the same thing.
        */
        'captcha_statuses' => [403, 429],

        'auth_wall_markers' => [
            'sign in to apply',
            'log in to apply',
            'login to apply',
            'sign in to continue',
            'log in to continue',
            'create an account to apply',
            'create an account to continue',
            'you must be signed in',
            'you must be logged in',
            'please sign in',
            'please log in',
            'login required',
            'sign in required',
        ],

        'auth_wall_title_markers' => [
            'sign in',
            'log in',
            'login',
            'create account',
        ],

        'auth_wall_url_markers' => [
            '/login',
            '/signin',
            '/sign-in',
            '/sign_in',
            '/auth/realms',
            '/accounts/login',
        ],

        'auth_wall_statuses' => [401],

    ],

    /*
    |--------------------------------------------------------------------------
    | Adapter registration order (Requirement 9.1)
    |--------------------------------------------------------------------------
    |
    | `App\Services\Apply\ApplyAdapterRegistry` walks this map top to bottom and
    | takes the FIRST adapter whose `supports($application_url)` returns true;
    | no later adapter is consulted. Order is therefore significant and is
    | decided here rather than in code:
    |
    |   - a narrow, specific adapter goes ABOVE a broader one
    |   - an adapter that could match "any careers page" goes LAST
    |
    | The three shipped adapters do not overlap — each claims its own vendor
    | hosts plus that vendor's own embedded-board tells — so this order is the
    | declaration order and nothing more. The keys match the `apply.adapters.*`
    | blocks below, and are the label that appears in the automation log and in
    | screenshot keys.
    |
    | `LinkedInEasyApplyAdapter` is deliberately NOT listed here: it is gated on
    | an explicit per-user opt-in and must never become reachable by a domain
    | match alone. It lives in `gated_registry` below.
    |
    */
    'registry' => [
        'greenhouse' => App\Services\Apply\Adapters\GreenhouseApplyAdapter::class,
        'lever' => App\Services\Apply\Adapters\LeverApplyAdapter::class,
        'workday' => App\Services\Apply\Adapters\WorkdayApplyAdapter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Opt-in-gated adapters (Requirement 9.3)
    |--------------------------------------------------------------------------
    |
    | Adapters a URL match is NOT sufficient to reach. Each must implement
    | `App\Services\Apply\OptInGatedApplyAdapter`, and the registry checks the
    | user's consent BEFORE it asks `supports()` — so for a user who has not
    | opted in, an adapter here is as good as unregistered.
    |
    | This is a separate map rather than a flag inside `registry` for exactly
    | that reason: anything in `registry` is reachable by a domain match, and
    | the one guarantee this list has to make is that these are not.
    |
    | LinkedIn Easy Apply drives the candidate's own signed-in LinkedIn account.
    | Automating it is, on a plain reading, against LinkedIn's User Agreement,
    | and what is at risk is the account rather than one submission — which is
    | why the user makes that call, once and explicitly, via
    | `user_automation_settings.linkedin_auto_apply_opt_in`.
    |
    */
    'gated_registry' => [
        'linkedin' => App\Services\Apply\Adapters\LinkedInEasyApplyAdapter::class,
    ],

    'adapters' => [

        'greenhouse' => [

            /*
            | Matched against the application URL's host, exactly or as a parent
            | domain (`greenhouse.io` therefore covers `boards.greenhouse.io`).
            */
            'hosts' => [
                'boards.greenhouse.io',
                'job-boards.greenhouse.io',
                'boards.eu.greenhouse.io',
                'job-boards.eu.greenhouse.io',
                'greenhouse.io',
            ],

            /*
            | Greenhouse embedded on a company's own careers domain. Only the
            | cheap, URL-only tells are listed: `supports()` runs for every
            | registered adapter on every attempt and must not fetch anything.
            | An embedded board that gives nothing away in its URL is simply not
            | claimed, and falls through to manual apply.
            */
            'embedded_query_params' => ['gh_jid', 'gh_src'],
            'embedded_path_markers' => ['/embed/job_app', '/greenhouse/'],

            /*
            | Requirement 9.7: applications this user may submit through this
            | platform in one day, on top of the user's own global cap
            | (`pipeline.daily_apply_cap`). Enforced by
            | `App\Services\Apply\ApplyDailyLimiter`; the lower of the two wins.
            |
            | This number is the ATS's tolerance rather than the user's
            | appetite, which is why it lives with the adapter and not in the
            | user's settings. Greenhouse boards are applied to anonymously and
            | per-company, so there is no single account to rate-limit — this is
            | a sanity ceiling, set just above the global default so it only
            | bites a user who raised theirs.
            */
            'daily_cap' => (int) env('APPLY_GREENHOUSE_DAILY_CAP', 25),

            'selectors' => [
                // Comma-joined alternatives on purpose: Playwright takes the
                // first match, so an older board and the current React form can
                // be covered by one step instead of branching the script.
                'form' => '#application_form, form#application-form, #application-form, [data-ui="application-form"]',
                /*
                | Read back before the form is touched, so a wall is detected
                | from the page's own words rather than inferred from a timeout
                | (Req 9.6). `body` is the one selector that cannot have moved.
                */
                'page_text' => 'body',
                'first_name' => '#first_name, input[name="job_application[first_name]"], input[autocomplete="given-name"]',
                'last_name' => '#last_name, input[name="job_application[last_name]"], input[autocomplete="family-name"]',
                'email' => '#email, input[name="job_application[email]"], input[autocomplete="email"]',
                'phone' => '#phone, input[name="job_application[phone]"], input[autocomplete="tel"]',
                'resume' => 'input[type="file"][name="job_application[resume]"], #resume input[type="file"], input[name="resume"]',
                'cover_letter' => 'input[type="file"][name="job_application[cover_letter]"], #cover_letter input[type="file"], input[name="cover_letter"]',
                'linkedin' => 'input[name*="urls[LinkedIn Profile]"], input[id*="urls_LinkedIn"]',
                'website' => 'input[name*="urls[Website]"], input[id*="urls_Website"]',
                'submit' => '#submit_app, button#submit_app, button[type="submit"]',
                'confirmation' => '#application_confirmation, .application-confirmation, #application-form-confirmation, [data-ui="confirmation"]',
            ],

            /*
            | The standard screening questions Greenhouse boards ask, and where
            | each answer comes from. Resolution order per question is: an
            | explicit answer on the `ApplyContext` (`answer_key`), then the
            | named profile attribute (`profile`), then `default`.
            |
            | Anything left unresolved is never guessed. What happens next is
            | decided by `required` (Requirement 9.4):
            |
            | - `required => true`  — the run is *paused before the submit
            |   click* and comes back `needs_review` naming this question, so a
            |   person answers it instead of the automation filing a form the
            |   ATS would reject (or, worse, accept with a blank answer).
            | - `required => false` — the question is left blank and the
            |   submission goes ahead; the label is still reported under
            |   `skipped_questions` for the audit trail.
            |
            | A question with no `required` key is treated as required: unknown
            | requiredness is not a licence to submit.
            |
            | `kind` is one of `fill`, `select`, `check`.
            */
            'questions' => [

                'work_authorization' => [
                    'label' => 'Are you legally authorized to work in the posting country?',
                    'selector' => 'select[name*="authorized"], select[id*="authorized"]',
                    'kind' => 'select',
                    'answer_key' => 'work_authorization',
                    'required' => true,
                    'default' => null,
                ],

                'visa_sponsorship' => [
                    'label' => 'Will you now or in the future require visa sponsorship?',
                    'selector' => 'select[name*="sponsorship"], select[id*="sponsorship"]',
                    'kind' => 'select',
                    'answer_key' => 'visa_sponsorship',
                    'required' => true,
                    'default' => null,
                ],

                'current_location' => [
                    'label' => 'Where are you currently located?',
                    'selector' => 'input[name*="location"], input[id*="candidate-location"]',
                    'kind' => 'fill',
                    'answer_key' => 'current_location',
                    'required' => false,
                    'profile' => 'location',
                    'default' => null,
                ],

                'how_did_you_hear' => [
                    'label' => 'How did you hear about this job?',
                    'selector' => 'select[name*="hear"], select[id*="hear"]',
                    'kind' => 'select',
                    'answer_key' => 'how_did_you_hear',
                    'required' => false,
                    'default' => null,
                ],

            ],

            /*
            | Confirmation evidence, matched case-insensitively against the
            | confirmation element's text, the page title, and the final URL.
            | Used as a second opinion: if the confirmation selector never
            | appeared but the page plainly says the application landed, the run
            | is `applied` rather than retried into a duplicate submission.
            */
            'confirmation_markers' => [
                'thank you for applying',
                'thanks for applying',
                'application submitted',
                'your application has been submitted',
                'we have received your application',
                'application received',
            ],

            'confirmation_url_markers' => [
                'confirmation',
                'thank',
                'application_confirmation',
            ],

            /*
            | Greenhouse's own additions to the shared `apply.blocked` lists
            | (Req 9.6). Boards are usually applicable anonymously, so the tells
            | here are the ones that only show up when they are not: a board
            | fronted by a bot check, and the "My Greenhouse" account gate some
            | boards put in front of the form.
            |
            | Nothing is ever bypassed: a match ends the run as `needs_review`
            | with the screenshots attached.
            */
            'captcha_markers' => [
                'please verify you are not a robot',
                'security verification required',
            ],

            'auth_wall_markers' => [
                'sign in to your my greenhouse account',
                'create a my greenhouse account to continue',
                'sign in to submit your application',
            ],

            /*
            | Milliseconds, per step. The form wait is generous because these
            | boards hydrate client-side; the confirmation wait is the longest
            | because that is the server round trip that actually files the
            | application.
            */
            'timeouts' => [
                'form_ms' => (int) env('APPLY_GREENHOUSE_FORM_TIMEOUT_MS', 20000),
                'upload_ms' => (int) env('APPLY_GREENHOUSE_UPLOAD_TIMEOUT_MS', 30000),
                'submit_ms' => (int) env('APPLY_GREENHOUSE_SUBMIT_TIMEOUT_MS', 20000),
                'confirmation_ms' => (int) env('APPLY_GREENHOUSE_CONFIRMATION_TIMEOUT_MS', 45000),
            ],

        ],

        'lever' => [

            /*
            | Only the candidate-facing boards. `hire.lever.co` is the employer
            | side of the product and must never be driven.
            */
            'hosts' => [
                'jobs.lever.co',
                'jobs.eu.lever.co',
            ],

            /* See the Greenhouse block: same sanity ceiling, same reasoning. */
            'daily_cap' => (int) env('APPLY_LEVER_DAILY_CAP', 25),

            'selectors' => [
                'form' => 'form.application-form, form[action*="/apply"], #application-form, .application-form',
                // Read back before the form is touched — see Greenhouse above.
                'page_text' => 'body',
                // One field, not two: Lever posts a single `name`, so no
                // surname guess is needed here.
                'name' => 'input[name="name"], #name',
                'email' => 'input[name="email"], #email',
                'phone' => 'input[name="phone"], #phone',
                // Lever's "current company".
                'company' => 'input[name="org"], #org',
                'location' => 'input[name="location"], input[name="cards[location]"], #location-input',
                'resume' => 'input[type="file"][name="resume"], #resume-upload-input, input[type="file"]',
                'linkedin' => 'input[name="urls[LinkedIn]"], input[name*="urls[LinkedIn"]',
                'github' => 'input[name="urls[GitHub]"], input[name*="urls[Github"]',
                'portfolio' => 'input[name="urls[Portfolio]"], input[name*="urls[Other"]',
                'submit' => 'button[type="submit"], .template-btn-submit, .postings-btn-wrapper button',
                'confirmation' => '.application-confirmation, .postings-confirmation, [data-qa="confirmation"], .content-wrapper h2',
            ],

            /*
            | Lever boards put custom questions in tenant-defined `cards[...]`
            | inputs whose names are not predictable, so only the two questions
            | Lever itself standardises are listed. Anything else is skipped and
            | reported rather than guessed.
            */
            'questions' => [

                'work_authorization' => [
                    'label' => 'Are you legally authorized to work in the posting country?',
                    'selector' => 'select[name*="authorized"], input[name*="authorized"]',
                    'kind' => 'select',
                    'answer_key' => 'work_authorization',
                    'required' => true,
                    'default' => null,
                ],

                'visa_sponsorship' => [
                    'label' => 'Will you now or in the future require visa sponsorship?',
                    'selector' => 'select[name*="sponsorship"], input[name*="sponsorship"]',
                    'kind' => 'select',
                    'answer_key' => 'visa_sponsorship',
                    'required' => true,
                    'default' => null,
                ],

            ],

            /*
            | Lever redirects to a `/thanks` page, which is the strongest signal
            | available and is matched by URL as well as by text.
            */
            'confirmation_markers' => [
                'thank you for applying',
                'thanks for applying',
                'application submitted',
                'we have received your application',
                'your application has been submitted',
            ],

            'confirmation_url_markers' => [
                '/thanks',
                'confirmation',
            ],

            /*
            | Lever fronts some boards with a bot check. It cannot be cleared by
            | a retry and is never bypassed, so it maps to `needs_review`
            | instead of burning attempts (Req 9.6).
            |
            | The challenge phrases now live in the shared `apply.blocked` lists;
            | what is left here is Lever's own wording. The vendor names are in
            | the *title* list on purpose: Lever's own form footer says "protected
            | by reCAPTCHA", so matching that against the page body would abort
            | every single Lever application.
            */
            'captcha_markers' => [
                'please complete the captcha to continue',
            ],

            'captcha_title_markers' => [
                'recaptcha',
                'hcaptcha',
            ],

            'timeouts' => [
                'form_ms' => (int) env('APPLY_LEVER_FORM_TIMEOUT_MS', 20000),
                'upload_ms' => (int) env('APPLY_LEVER_UPLOAD_TIMEOUT_MS', 30000),
                'submit_ms' => (int) env('APPLY_LEVER_SUBMIT_TIMEOUT_MS', 20000),
                'confirmation_ms' => (int) env('APPLY_LEVER_CONFIRMATION_TIMEOUT_MS', 45000),
            ],

        ],

        'workday' => [

            /*
            | Tenants live on `{tenant}.wd{N}.myworkdayjobs.com` (N currently
            | runs into the hundreds) and on `myworkdaysite.com`. Both are
            | matched as parent domains, so every numbered shard is covered
            | without listing them.
            */
            'hosts' => [
                'myworkdayjobs.com',
                'myworkdaysite.com',
            ],

            /*
            | The `wdNN` label is Workday's own tell and survives a vanity
            | domain (`careers.acme.com.wd5...`-style CNAMEs), so it is matched
            | as a pattern against the host. Anything without it is not claimed.
            */
            'host_patterns' => [
                '#\.wd\d{1,3}\.#',
            ],

            'embedded_path_markers' => ['/wday/'],

            /*
            | Lower than the other two. A Workday run is a multi-page wizard
            | against one tenant, which is both the slowest apply path and the
            | one most likely to be read as scripted traffic.
            */
            'daily_cap' => (int) env('APPLY_WORKDAY_DAILY_CAP', 15),

            'selectors' => [
                // `data-automation-id` is Workday's own test hook and is far
                // more stable across tenants than classes or ids.
                'posting' => '[data-automation-id="jobPostingPage"], [data-automation-id="jobPostingHeader"], body',
                'page_text' => 'body',
                'apply' => '[data-automation-id="adventureButton"], a[data-automation-id="applyManually"], [data-automation-id="applyManually"]',
                'form' => '[data-automation-id="legalNameSection_firstName"], [data-automation-id="userEmail"], [data-automation-id="applyFlowPage"]',
                'first_name' => '[data-automation-id="legalNameSection_firstName"], input[name="firstName"]',
                'last_name' => '[data-automation-id="legalNameSection_lastName"], input[name="lastName"]',
                'email' => '[data-automation-id="email"], [data-automation-id="userEmail"], input[type="email"]',
                'phone' => '[data-automation-id="phone-number"], [data-automation-id="phoneNumber"], input[type="tel"]',
                'resume' => '[data-automation-id="file-upload-input-ref"], input[type="file"]',
                /*
                | Left empty on purpose: only some tenants expose a second
                | attachment input, and uploading into a selector that may not
                | exist would fail the run before the submit click and earn a
                | pointless retry. Set it per deployment if a tenant has one.
                */
                'cover_letter' => env('APPLY_WORKDAY_COVER_LETTER_SELECTOR', ''),
                'submit' => '[data-automation-id="bottom-navigation-next-button"], [data-automation-id="wd-CommandButton_uic_okButton"], button[data-automation-id*="submit"]',
                'confirmation' => '[data-automation-id="successMessage"], [data-automation-id="applicationSubmitted"], [data-automation-id="confirmationPage"]',
            ],

            /*
            | Substring attribute matching is CASE SENSITIVE, and Workday names
            | its automation ids and fields in camelCase
            | (`legalNameSection_firstName`, `requiresSponsorship`). A lowercase
            | `*="sponsorship"` therefore misses the field on any tenant whose
            | id capitalises the word, which is most of them — caught by the
            | fixture harness in `ApplyFixtureSelectorTest`, not by a timeout in
            | production. Both casings are listed per question; the leading
            | letter is all that varies in practice.
            */
            'questions' => [

                'work_authorization' => [
                    'label' => 'Are you legally authorized to work in the posting country?',
                    'selector' => '[data-automation-id*="authorized"] select, [data-automation-id*="Authorized"] select, select[name*="authorized"], select[name*="Authorized"]',
                    'kind' => 'select',
                    'answer_key' => 'work_authorization',
                    'required' => true,
                    'default' => null,
                ],

                'visa_sponsorship' => [
                    'label' => 'Will you now or in the future require visa sponsorship?',
                    'selector' => '[data-automation-id*="sponsorship"] select, [data-automation-id*="Sponsorship"] select, select[name*="sponsorship"], select[name*="Sponsorship"]',
                    'kind' => 'select',
                    'answer_key' => 'visa_sponsorship',
                    'required' => true,
                    'default' => null,
                ],

                'how_did_you_hear' => [
                    'label' => 'How did you hear about this job?',
                    'selector' => '[data-automation-id*="source"] select, [data-automation-id*="Source"] select, select[name*="source"], select[name*="Source"]',
                    'kind' => 'select',
                    'answer_key' => 'how_did_you_hear',
                    'required' => false,
                    'default' => null,
                ],

            ],

            'confirmation_markers' => [
                'you have successfully submitted',
                'your application has been submitted',
                'application submitted',
                'thank you for your interest',
                'we have received your application',
            ],

            'confirmation_url_markers' => [
                'confirmation',
                'successfullysubmitted',
            ],

            /*
            | The common Workday outcome: an account is required before the
            | wizard will start. These are on top of the shared
            | `apply.blocked.auth_wall_markers`, and are matched against the
            | page text the script reads back from `body` before the form is
            | touched — so a wall is named as a wall rather than reported as
            | selector rot (Req 9.6). Never bypassed; the run ends as
            | `needs_review`.
            */
            'auth_wall_markers' => [
                'sign in to apply',
                'create account',
                'create an account',
                'already have an account',
                'sign in with your email',
                'please sign in',
                'use my last application',
            ],

            'already_applied_markers' => [
                'you have already applied',
                'already submitted an application',
                'you already have an application',
            ],

            /*
            | Vendor names stay in the title scope for the same reason as Lever:
            | a tenant that merely loads a bot-check script is not a tenant that
            | is challenging us. The challenge phrases come from
            | `apply.blocked.captcha_markers`.
            */
            'captcha_title_markers' => [
                'recaptcha',
                'hcaptcha',
            ],

            /*
            | Longer than the single-page ATSs across the board: Workday renders
            | its wizard client-side and each transition is a server round trip.
            */
            'timeouts' => [
                'page_ms' => (int) env('APPLY_WORKDAY_PAGE_TIMEOUT_MS', 30000),
                'apply_ms' => (int) env('APPLY_WORKDAY_APPLY_TIMEOUT_MS', 30000),
                'form_ms' => (int) env('APPLY_WORKDAY_FORM_TIMEOUT_MS', 30000),
                'upload_ms' => (int) env('APPLY_WORKDAY_UPLOAD_TIMEOUT_MS', 45000),
                'submit_ms' => (int) env('APPLY_WORKDAY_SUBMIT_TIMEOUT_MS', 30000),
                'confirmation_ms' => (int) env('APPLY_WORKDAY_CONFIRMATION_TIMEOUT_MS', 60000),
            ],

        ],

        /*
        | LinkedIn Easy Apply (Requirement 9.3). Registered in
        | `gated_registry`, not `registry`, so it is unreachable by a domain
        | match and runs only for a user who has explicitly opted in. See
        | `App\Services\Apply\Adapters\LinkedInEasyApplyAdapter`.
        */
        'linkedin' => [

            /*
            | Host match alone is not enough here — `linkedin.com` covers
            | profiles, feeds and company pages too — so the adapter also
            | insists on one of `job_path_markers` below.
            */
            'hosts' => [
                'linkedin.com',
                'www.linkedin.com',
            ],

            /*
            | A LinkedIn URL this adapter will claim: the job view, and the
            | search page's selected-posting form. Anything else on the domain
            | is left alone rather than opened in a browser.
            */
            'job_path_markers' => [
                '/jobs/view/',
                '/jobs/collections/',
                '/jobs/search/',
            ],

            /*
            | Requirement 9.7, and the strictest of the four caps by design.
            | Easy Apply runs against the user's own signed-in LinkedIn account,
            | so the cost of looking automated is that account, not a failed
            | submission. The user's global LinkedIn cap
            | (`pipeline.linkedin_daily_apply_cap`) applies as well and the
            | lower of the two wins.
            */
            'daily_cap' => (int) env('APPLY_LINKEDIN_DAILY_CAP', 5),

            /*
            | How many "Next" clicks the Easy Apply modal is assumed to need
            | before the "Submit application" button. The worker runs a
            | straight-line script and cannot count panels, so this is a
            | declaration rather than a discovery.
            |
            | Two is the common shape (Contact info → Resume → Review). Fewer
            | panels than this fails on an extra "Next" *before* the submit
            | click, which files nothing; more panels means the submit click
            | lands on a "Next" and the confirmation never arrives, which the
            | submit-boundary rule turns into `needs_review` rather than a
            | retry. Both are hand-offs, which is the point. Clamped to 0..4.
            */
            'modal_pages' => (int) env('APPLY_LINKEDIN_MODAL_PAGES', 2),

            'selectors' => [
                // LinkedIn's `jobs-*` component classes are the only stable-ish
                // hook; `artdeco-*` is the design system and changes less often
                // than the feature classes around it.
                'posting' => '.jobs-details, .job-view-layout, .jobs-search__job-details, body',
                'page_text' => 'body',
                'easy_apply' => '.jobs-apply-button, button.jobs-apply-button--top-card, button[aria-label*="Easy Apply"]',
                'modal' => '.jobs-easy-apply-modal, [data-test-modal-id="easy-apply-modal"], .artdeco-modal--layer-default',
                'first_name' => 'input[id*="first-name"], input[name="firstName"]',
                'last_name' => 'input[id*="last-name"], input[name="lastName"]',
                'email' => 'select[id*="email"], input[id*="email"], input[type="email"]',
                'phone' => 'input[id*="phoneNumber-nationalNumber"], input[id*="phone"], input[type="tel"]',
                'resume' => 'input[type="file"][id*="jobs-document-upload-file-input"], .jobs-document-upload input[type="file"], input[type="file"]',
                /*
                | Easy Apply has no cover-letter input of its own. Left empty so
                | the step is skipped rather than failing a run on a selector
                | that does not exist; the unattached document is reported in
                | the automation log instead.
                */
                'cover_letter' => env('APPLY_LINKEDIN_COVER_LETTER_SELECTOR', ''),
                'next' => 'button[aria-label*="Continue to next step"], button[aria-label*="Next"], .artdeco-button--primary[data-easy-apply-next-button]',
                'submit' => 'button[aria-label*="Submit application"], button[aria-label*="Submit"]',
                'confirmation' => '.artdeco-modal__content h2, .jobs-easy-apply-confirmation, [data-test-modal-id="easy-apply-success-modal"], .jobs-s-apply-success',
            ],

            /*
            | Easy Apply's own standard questions. Employer-defined ones are
            | rendered with generated ids that are not predictable, so they are
            | not listed — the form's own validation then blocks the submission
            | and the run lands in `needs_review` at the submit boundary rather
            | than filing a half-answered application.
            */
            'questions' => [

                'work_authorization' => [
                    'label' => 'Are you legally authorized to work in the posting country?',
                    'selector' => 'select[id*="authorized"], fieldset[id*="authorized"] select',
                    'kind' => 'select',
                    'answer_key' => 'work_authorization',
                    'required' => true,
                    'default' => null,
                ],

                'visa_sponsorship' => [
                    'label' => 'Will you now or in the future require visa sponsorship?',
                    'selector' => 'select[id*="sponsorship"], fieldset[id*="sponsorship"] select',
                    'kind' => 'select',
                    'answer_key' => 'visa_sponsorship',
                    'required' => true,
                    'default' => null,
                ],

            ],

            /*
            | Deliberately narrow. These are matched against the page title and
            | the raw HTML as well as the confirmation element, and a LinkedIn
            | job page is full of other postings' copy — a loose marker like
            | "applied" would turn a neighbouring job card into false evidence
            | that this application landed, which is the one mistake that must
            | not happen. Only full phrases LinkedIn uses on success are listed.
            */
            'confirmation_markers' => [
                'your application was sent',
                'your application has been submitted',
                'application sent to',
            ],

            'confirmation_url_markers' => [
                'post-apply',
            ],

            /*
            | The expected LinkedIn outcome for a worker with no signed-in
            | session: the authwall. Never bypassed and never signed into on the
            | user's behalf — the run ends as `needs_review` with the
            | screenshots (Req 9.6).
            */
            'auth_wall_markers' => [
                'sign in to view',
                'sign in to see',
                'join linkedin',
                'join now to see',
                'sign in to continue to linkedin',
                'new to linkedin? join now',
            ],

            'auth_wall_url_markers' => [
                '/authwall',
                '/uas/login',
                '/checkpoint/lg/login',
                'session_redirect',
            ],

            /*
            | LinkedIn's bot-detection checkpoint. A brand name in the title
            | scope only, for the same reason as Lever and Workday.
            */
            'captcha_markers' => [
                'let\'s do a quick security check',
                'security verification',
            ],

            'captcha_url_markers' => [
                '/checkpoint/challenge',
            ],

            'captcha_title_markers' => [
                'recaptcha',
                'security verification',
            ],

            /*
            | A posting already applied to shows "Applied" in place of the Easy
            | Apply button. Conclusive, and a duplicate is the one thing worse
            | than a review item.
            */
            'already_applied_markers' => [
                'you have already applied to this job',
                'applied on',
                'application submitted on',
            ],

            'timeouts' => [
                'page_ms' => (int) env('APPLY_LINKEDIN_PAGE_TIMEOUT_MS', 30000),
                'modal_ms' => (int) env('APPLY_LINKEDIN_MODAL_TIMEOUT_MS', 30000),
                'upload_ms' => (int) env('APPLY_LINKEDIN_UPLOAD_TIMEOUT_MS', 45000),
                'next_ms' => (int) env('APPLY_LINKEDIN_NEXT_TIMEOUT_MS', 20000),
                'submit_ms' => (int) env('APPLY_LINKEDIN_SUBMIT_TIMEOUT_MS', 30000),
                'confirmation_ms' => (int) env('APPLY_LINKEDIN_CONFIRMATION_TIMEOUT_MS', 45000),
            ],

        ],

    ],

];
