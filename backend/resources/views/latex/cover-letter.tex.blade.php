<<-- ==========================================================================
     Base cover-letter template (Requirement 7.1)

     Sibling of resume.tex.blade.php and rendered by the same `latex.blade`
     engine (App\Providers\LatexViewServiceProvider), so the delimiters are the
     same non-stock set:

         << $value >>    escaped echo  -> LatexEscaper::escape()
         <<! $tex !>>    raw, unescaped -- not used here, and must not be
         angle-dash      comment form, as used by this block; stripped from the
                         .tex output. It cannot be shown inline because the
                         example would terminate the comment.

     Every value below goes through the escaped form, including the ones that
     look harmless (a date, a closing). That is the Requirement 6.3 guarantee
     carried over to cover letters, and as in the resume template it holds by
     construction: the echo format of this dialect *is* the escaper, so an `&`
     in a company name or a `%` in a body paragraph cannot reach the compiler.

     ------------------------------------------------------------------ data
     The render service (task 12.3, `renderCoverLetter()`) flattens UserProfile
     plus the tailoring model's structured JSON -- never raw LaTeX from the
     model -- into:

       name             ?string   candidate name, for the sender block and the
                                  signature line
       contact          list<string>  email/phone/location/URLs, already
                                  formatted by the caller
       date             ?string   already formatted for display; the template
                                  does no date formatting, because locale and
                                  timezone are the caller's business and a
                                  Carbon instance would stringify to a SQL
                                  timestamp here
       recipient_name   ?string   hiring manager, when the posting names one
       recipient_title  ?string   their title, e.g. "Engineering Manager"
       company          ?string
       company_address  list<string>  address lines, one per element
       job_title        ?string   the posting's title, used in the Re: line
       paragraphs       list<string>  the body, in the order the model returned
                                  it. One element per paragraph; the model
                                  supplies prose, not markup
       closing          ?string   e.g. "Sincerely," -- defaults below
       signature        ?string   defaults to `name`

     Every key is optional. Each block guards on emptiness, so a posting with
     no named contact, a company with no address, a missing date, or an empty
     paragraph list all still yield a compilable document rather than a stray
     `\textbf{}` or a "Dear ,".

     ------------------------------------------------- salutation and closing
     The "Dear Hiring Manager" / "Sincerely," fallbacks live here rather than in
     the caller, and that is a deliberate choice about where a *degradation*
     rule belongs. These strings are not tailored content -- the model's output
     is `paragraphs`, and design.md keeps it to structured content -- they are
     the answer to "what does this line say when the field it depends on is
     absent". Putting that answer in the template means the no-recipient-name
     case cannot be got wrong by a future second caller, and it is the same kind
     of rule as dropping an empty section: local to layout, invisible to the
     pipeline. A caller that wants different wording still overrides it by
     passing `closing`; the salutation is derived from `recipient_name`, so
     supplying the name is how a caller changes that line.

     ------------------------------------------------------- layout mechanics
     The two rules from the resume template apply unchanged, and are why this
     looks unidiomatic for LaTeX:

       * Vertical breaks are `\par`, not `\\`. A `\\` with nothing after it --
         exactly what an absent trailing field leaves behind -- raises "There's
         no line here to end" and fails the compile.
       * Vertical space is emitted *before* the block it separates, never after,
         so it can never dangle at the end of a document whose last block was
         omitted.

     Optional fragments each sit on their own template line, ending in a LaTeX
     `%` where the following text must butt straight up against them (a bare
     newline typesets as a space, giving "jane@example.com | +1 555"). Two
     directives cannot be run together on one line to avoid this: Blade's
     statement pattern is anchored with `\B`, so an `@if` immediately after a
     word character -- `@endif@foreach` -- is silently left uncompiled and
     emitted as literal text.
     -->>
\documentclass[11pt,letterpaper]{article}

% `article`, not `letter`/`scrlttr2`. A letter class would take over the page
% layout and impose its own \opening/\closing/\address macros, none of which
% degrade quietly when a field is absent -- \opening{} with an empty argument
% still typesets its punctuation, which is the failure mode this template exists
% to avoid. The document here is six stacked blocks of text; `article` plus
% \par is the whole requirement, and it keeps this file structurally identical
% to resume.tex.blade.php.
%
% Preamble is deliberately minimal for the same reason as the resume template:
% tectonic fetches packages on demand, so every \usepackage is a network
% dependency at compile time and a possible failure in a container. Both of
% these are already proven present by resume.tex.blade.php -- nothing new is
% introduced. enumitem and titlesec are omitted because a letter has no lists
% and no \section headings.
% No inputenc/fontenc: UTF-8 is the default input encoding for both pdflatex and
% tectonic's XeTeX, and the two want different fontenc settings -- omitting it
% keeps this engine-agnostic (see config/latex.php).
\usepackage[margin=1in]{geometry}
\usepackage[hidelinks]{hyperref}

% 1in rather than the resume's 0.75in: this document is running prose, and a
% resume's tighter measure exists to fit dense bullets, not to be read as
% paragraphs.

\pagestyle{empty}
\setlength{\parindent}{0pt}
% Block-style letter: paragraphs are separated by space, not indentation, which
% is also what keeps \par-separated blocks legible without any \\ anywhere.
\setlength{\parskip}{0.75em}

\hypersetup{pdftitle={<< $name ?? 'Cover Letter' >>},pdfauthor={<< $name ?? '' >>}}

\begin{document}

<<-- Sender block. The contact parts are joined inline with separators rather
     than stacked, so a profile with no phone or portfolio URL leaves no gap. -->>
@php($contactParts = array_values(array_filter(array_map('strval', $contact ?? []), fn ($part) => trim($part) !== '')))
@if(trim((string) ($name ?? '')) !== '')
{\large\bfseries << $name >>}\par
@endif
@if($contactParts !== [])
@foreach($contactParts as $part)
@if(! $loop->first)
\quad\textbar\quad
@endif
<< $part >>%
@endforeach
\par
@endif
@if(trim((string) ($date ?? '')) !== '')
\vspace{1.4em}
<< $date >>\par
@endif
<<-- Recipient block. Each known line stands alone, so an unnamed contact simply
     starts the block at the company, and a company with no address ends it
     there. -->>
@php($addressLines = array_values(array_filter(array_map('strval', $company_address ?? []), fn ($line) => trim($line) !== '')))
@php($hasRecipientBlock = trim((string) ($recipient_name ?? '').(string) ($recipient_title ?? '').(string) ($company ?? '')) !== '' || $addressLines !== [])
@if($hasRecipientBlock)
\vspace{1.4em}
@if(trim((string) ($recipient_name ?? '')) !== '')
<< $recipient_name >>\par
@endif
@if(trim((string) ($recipient_title ?? '')) !== '')
<< $recipient_title >>\par
@endif
@if(trim((string) ($company ?? '')) !== '')
<< $company >>\par
@endif
@foreach($addressLines as $line)
<< $line >>\par
@endforeach
@endif
<<-- Salutation. Always present: a letter with no greeting reads as a truncated
     render, and the fallback costs nothing. -->>
\vspace{1.4em}
@if(trim((string) ($recipient_name ?? '')) !== '')
Dear << $recipient_name >>,\par
@else
Dear Hiring Manager,\par
@endif
<<-- Subject line. This is where the posting is identified, so the opening
     paragraph does not have to carry the job title and company itself -- and so
     the two facts are present even if the model's prose omits them. -->>
@php($jobTitle = trim((string) ($job_title ?? '')))
@php($companyName = trim((string) ($company ?? '')))
@if($jobTitle !== '' && $companyName !== '')
\textbf{Re: << $jobTitle >> at << $companyName >>}\par
@elseif($jobTitle !== '')
\textbf{Re: << $jobTitle >>}\par
@elseif($companyName !== '')
\textbf{Re: Application to << $companyName >>}\par
@endif
<<-- Body. Order is the model's output and is preserved as given; blank
     paragraphs are dropped rather than emitted as empty \par pairs, which would
     open a visible gap in the letter. -->>
@php($bodyParagraphs = array_values(array_filter(array_map('strval', $paragraphs ?? []), fn ($paragraph) => trim($paragraph) !== '')))
@if($bodyParagraphs !== [])
\vspace{0.6em}
@foreach($bodyParagraphs as $paragraph)
<< $paragraph >>\par
@endforeach
@endif
@php($closingLine = trim((string) ($closing ?? '')) !== '' ? (string) $closing : 'Sincerely,')
@php($signatureLine = trim((string) ($signature ?? '')) !== '' ? (string) $signature : (string) ($name ?? ''))
\vspace{1.4em}
<< $closingLine >>\par
@if(trim($signatureLine) !== '')
\vspace{2.2em}
<< $signatureLine >>\par
@endif
\end{document}
