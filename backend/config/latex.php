<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LaTeX compilation (Requirement 6.2)
    |--------------------------------------------------------------------------
    |
    | Settings for the process that turns a generated .tex document into a PDF.
    | Everything the compiler needs — which binary, where to find it, how long
    | to wait, where to work — lives here rather than as a literal in the
    | rendering code, so a deployment can retarget its TeX install without a
    | code change.
    |
    */

    /*
    | The TeX engine to run. Defaults to tectonic: it ships as a single
    | self-contained Rust binary with its TeX packages bundled, so a Docker
    | image needs neither a full TeX Live install nor the multi-gigabyte layer
    | that comes with one — which is what makes it practical to build here at
    | all, unlike pdflatex or xelatex.
    |
    | pdflatex stays supported as a fallback for environments where tectonic
    | isn't available (an existing TeX Live host, a platform with no tectonic
    | build). Select it with LATEX_ENGINE=pdflatex.
    */
    'engine' => env('LATEX_ENGINE', 'tectonic'),

    /*
    | Explicit absolute path to the engine binary. Null — the default — means
    | resolve `engine` from PATH, which is the normal case inside the container
    | where the binary is installed to a standard location. Set this when the
    | install lives somewhere unusual, or when PATH differs between the shell
    | and the process running the queue worker.
    */
    'binary' => env('LATEX_BINARY', null),

    /*
    | Seconds allowed for one compile before the process is killed. A LaTeX run
    | that hasn't finished by now is not slow, it's stuck — most often waiting
    | on stdin for input that will never arrive after an error in the document.
    | The cap turns that into a failed render instead of a hung worker.
    */
    'timeout' => (int) env('LATEX_TIMEOUT', 120),

    /*
    | Directory the compiler runs in. LaTeX writes its auxiliary files (.aux,
    | .log, .out) next to the source, so this needs to be writable and is kept
    | out of the way of application code.
    */
    'working_directory' => env('LATEX_WORKING_DIRECTORY', storage_path('app/latex')),

    /*
    |--------------------------------------------------------------------------
    | Resume tailoring (Requirement 6.1)
    |--------------------------------------------------------------------------
    |
    | Read by App\Services\Tailoring\ResumeTailoringService — the LLM half of
    | tailoring, which produces the structured content the template above is
    | rendered with. Same rule as config/scoring.php: nothing here is
    | duplicated as a literal in the service, so prompt budgets and output
    | shape can be retuned without a code change.
    |
    | The budgets exist for two different reasons and are tuned separately.
    | The *input* budgets (description/profile) cap the token bill of one
    | tailoring run. The *output* budgets (bullet counts, character caps) cap
    | the physical page: a resume is a one- or two-page document, and a model
    | asked for "strong bullets" will happily return eight per role and
    | overflow it. Enforcing them in code rather than only in the prompt means
    | a model that ignores the instruction still yields a document that fits.
    |
    */

    'tailoring' => [

        /*
        | Attempts allowed for the tailoring prompt. Mirrors
        | `scoring.max_attempts` (Requirement 5.5's contract, applied here):
        | one normal attempt, then one carrying a stricter formatting
        | instruction naming what went wrong. Clamped to at least 1.
        |
        | Distinct from `pipeline.latex_retry_max` (Requirement 6.4, task
        | 11.4), which bounds re-prompting after a *compile* failure. This one
        | bounds re-prompting after an unusable *response*, and is spent before
        | any LaTeX engine has run.
        */
        'max_attempts' => (int) env('TAILORING_MAX_ATTEMPTS', 2),

        /*
        | Characters of job-description text the tailoring prompt sees. Smaller
        | than scoring's budget: scoring has to weigh every stated requirement,
        | whereas tailoring only needs the responsibilities and keywords to
        | mirror, which sit near the top of a posting.
        */
        'max_description_chars' => (int) env('TAILORING_MAX_DESCRIPTION_CHARS', 12000),

        /*
        | Characters of the serialized candidate profile (roles, achievements,
        | skills, education) sent with the prompt.
        */
        'max_profile_chars' => (int) env('TAILORING_MAX_PROFILE_CHARS', 12000),

        /*
        | Most recent roles offered for tailoring. Older roles beyond this are
        | dropped from both the prompt and the document — a resume does not
        | list a fifteen-year-old internship, and every extra role costs prompt
        | tokens and page space.
        */
        'max_experience_entries' => (int) env('TAILORING_MAX_EXPERIENCE_ENTRIES', 6),

        /*
        | Bullets kept per role, and the length of one bullet. A bullet longer
        | than this wraps to three lines and stops reading as a bullet.
        */
        'max_bullets_per_role' => (int) env('TAILORING_MAX_BULLETS_PER_ROLE', 4),
        'max_bullet_chars' => (int) env('TAILORING_MAX_BULLET_CHARS', 300),

        /*
        | Length of the tailored professional summary. Roughly three or four
        | lines at the template's margins.
        */
        'max_summary_chars' => (int) env('TAILORING_MAX_SUMMARY_CHARS', 700),

        /*
        | Length of the headline under the candidate's name (the target job
        | title, e.g. "Senior Backend Engineer").
        */
        'max_headline_chars' => (int) env('TAILORING_MAX_HEADLINE_CHARS', 120),

        /*
        | Skills listed per group in the rendered document, and how many of the
        | model's emphasized skills are honoured as "put these first". Both are
        | display caps; neither lets the model add a skill the profile does not
        | already claim.
        */
        'max_skills_per_group' => (int) env('TAILORING_MAX_SKILLS_PER_GROUP', 18),
        'max_emphasized_skills' => (int) env('TAILORING_MAX_EMPHASIZED_SKILLS', 15),

        /*
        | Display labels for the skill groups the resume parser writes
        | (`user_profiles.skills` is `{primary: [...], secondary: [...]}`).
        | A group whose key is not listed here is labelled with
        | `default_skill_group_label`, and a numerically-keyed (ungrouped)
        | skills list gets no label at all so it renders as one plain row.
        */
        'skill_group_labels' => [
            'primary' => 'Core Skills',
            'secondary' => 'Additional Skills',
        ],
        'default_skill_group_label' => 'Skills',

        /*
        | The template key handed to LatexRenderService::renderResume() when a
        | caller does not choose one (Requirement 6.3).
        */
        'default_template_key' => env('TAILORING_TEMPLATE_KEY', 'default'),

    ],

];
