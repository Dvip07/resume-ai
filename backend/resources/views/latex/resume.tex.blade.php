<<-- ==========================================================================
     Base resume template (Requirements 6.1, 6.3)

     Rendered by the `latex.blade` engine registered in
     App\Providers\LatexViewServiceProvider, so the delimiters here are NOT
     stock Blade:

         << $value >>    escaped echo  -> LatexEscaper::escape()
         <<! $tex !>>    raw, unescaped -- do not use for model or user data
         angle-dash      comment form, as used by this block; stripped from
                         the .tex output. It cannot be shown inline here
                         because the example would terminate the comment.

     Every value below goes through the escaped form. That is the Requirement
     6.3 guarantee, and it holds by construction rather than by review: the
     echo format of this dialect *is* the LaTeX escaper, so a `&` in a company
     name or a `%` in a bullet cannot reach the compiler unescaped.

     ------------------------------------------------------------------ data
     The render service (task 11.3) flattens UserProfile plus the tailoring
     model's structured JSON -- never raw LaTeX from the model -- into:

       name        string
       headline    ?string   target title, e.g. "Senior Backend Engineer"
       contact     list<string>   email/phone/location/URLs, already formatted
       summary     ?string   tailored professional summary
       skills      list<array{label: ?string, items: list<string>}>
                             emphasized skills first, grouped for display
       experience  list<array{position, company, location, dates,
                              bullets: list<string>}>
                             bullets arrive pre-ordered by the model
       education   list<array{degree, field, institution, location, dates}>

     Every key is optional. Sections guard on emptiness so a profile with no
     education, an empty summary, or a role whose bullets were all dropped
     still yields a compilable document instead of a stray \section{} or an
     itemize with no \item (which is a hard LaTeX error, not a cosmetic one).

     ------------------------------------------------------- layout mechanics
     Two rules keep the generated source valid regardless of which fields are
     present, and both are why this looks slightly unidiomatic for LaTeX:

       * Vertical breaks are `\par`, not `\\`. A `\\` with nothing after it --
         which is what an absent trailing field would leave behind -- raises
         "There's no line here to end" and fails the compile.
       * Where `\\[..]` spacing is genuinely wanted (the header), it is emitted
         *before* the line it separates, never after, so it can never end up
         dangling at the end of a block.

     Each optional fragment therefore sits on its own template line, ending in
     a LaTeX `%` where the following text must butt straight up against it (a
     bare newline would typeset as a space, giving "Developer , Acme"). Two
     directives cannot simply be run together on one line to avoid this:
     Blade's statement pattern is anchored with `\B`, so an `@if` immediately
     after a word character -- `@endif@foreach` -- is silently left
     uncompiled and emitted as literal text.
     -->>
\documentclass[11pt,letterpaper]{article}

% Preamble is deliberately minimal: tectonic fetches packages on demand from
% its bundle, so every \usepackage is a network dependency at compile time and
% a possible failure in a container. These four are in the default bundle.
% No inputenc/fontenc: UTF-8 is the default input encoding for both pdflatex
% and tectonic's XeTeX, and the two engines want different fontenc settings --
% omitting it keeps this document engine-agnostic (see config/latex.php).
\usepackage[margin=0.75in]{geometry}
\usepackage{enumitem}
\usepackage{titlesec}
\usepackage[hidelinks]{hyperref}

% Compact, rule-underlined section headings -- the conventional resume look.
\titleformat{\section}{\large\bfseries\scshape}{}{0em}{}[\vspace{-0.7em}\rule{\linewidth}{0.5pt}]
\titlespacing*{\section}{0pt}{1.1em}{0.6em}

% Bullets sit tight under their role. parsep=0 keeps a multi-line bullet from
% reading as two bullets.
\setlist[itemize]{leftmargin=1.4em,topsep=0.2em,itemsep=0.15em,parsep=0pt,label=\textbullet}

\pagestyle{empty}
\setlength{\parindent}{0pt}

\hypersetup{pdftitle={<< $name ?? 'Resume' >>},pdfauthor={<< $name ?? '' >>}}

\begin{document}

<<-- Contact header. Parts are joined on one line with separators rather than
     stacked, so a profile with no phone or portfolio URL leaves no gap. -->>
@php($contactParts = array_values(array_filter(array_map('strval', $contact ?? []), fn ($part) => trim($part) !== '')))
\begin{center}
{\LARGE\bfseries << $name ?? '' >>}%
@if(trim((string) ($headline ?? '')) !== '')
\\[0.35em]
{\large << $headline >>}%
@endif
@if($contactParts !== [])
\\[0.35em]
@foreach($contactParts as $part)
@if(! $loop->first)
\quad\textbar\quad
@endif
<< $part >>%
@endforeach
@endif
\end{center}

@if(trim((string) ($summary ?? '')) !== '')
\section{Summary}
<< $summary >>\par
@endif
<<-- A group whose items are all blank is dropped entirely: it would otherwise
     render as a label with nothing after it. If every group drops, the section
     heading goes too. -->>
@php($skillGroups = array_values(array_filter(array_map(fn ($group) => ['label' => $group['label'] ?? null, 'items' => array_values(array_filter(array_map('strval', $group['items'] ?? []), fn ($item) => trim($item) !== ''))], $skills ?? []), fn ($group) => $group['items'] !== [])))
@if($skillGroups !== [])
\section{Skills}
@foreach($skillGroups as $group)
@if(trim((string) ($group['label'] ?? '')) !== '')
\textbf{<< $group['label'] >>:}
@endif
@foreach($group['items'] as $item)
@if(! $loop->first)
,
@endif
<< $item >>%
@endforeach
\par
@endforeach
@endif
<<-- An entry with neither an employer nor a title is unusable in a resume and
     is almost certainly a parsing artefact, so it is dropped rather than
     rendered as a heading-less bullet list. -->>
@php($roles = array_values(array_filter($experience ?? [], fn ($role) => trim((string) ($role['company'] ?? '').(string) ($role['position'] ?? '')) !== '')))
@if($roles !== [])
\section{Experience}
@foreach($roles as $role)
@php($bullets = array_values(array_filter(array_map('strval', $role['bullets'] ?? []), fn ($bullet) => trim($bullet) !== '')))
@if(trim((string) ($role['position'] ?? '')) !== '')
\textbf{<< $role['position'] >>}%
@if(trim((string) ($role['company'] ?? '')) !== '')
,
@endif
@endif
@if(trim((string) ($role['company'] ?? '')) !== '')
<< $role['company'] >>%
@endif
@if(trim((string) ($role['dates'] ?? '')) !== '')
\hfill << $role['dates'] >>%
@endif
\par
@if(trim((string) ($role['location'] ?? '')) !== '')
\textit{<< $role['location'] >>}\par
@endif
@if($bullets !== [])
\begin{itemize}
@foreach($bullets as $bullet)
    \item << $bullet >>
@endforeach
\end{itemize}
@endif
@if(! $loop->last)
\medskip
@endif
@endforeach
@endif
@php($schools = array_values(array_filter($education ?? [], fn ($school) => trim((string) ($school['institution'] ?? '').(string) ($school['degree'] ?? '')) !== '')))
@if($schools !== [])
\section{Education}
@foreach($schools as $school)
@if(trim((string) ($school['institution'] ?? '')) !== '')
\textbf{<< $school['institution'] >>}%
@endif
@if(trim((string) ($school['dates'] ?? '')) !== '')
\hfill << $school['dates'] >>%
@endif
\par
@if(trim((string) ($school['degree'] ?? '')) !== '')
<< $school['degree'] >>%
@if(trim((string) ($school['field'] ?? '')) !== '')
,
@endif
@endif
@if(trim((string) ($school['field'] ?? '')) !== '')
<< $school['field'] >>%
@endif
@if(trim((string) ($school['location'] ?? '')) !== '')
@if(trim((string) ($school['degree'] ?? '')) !== '' || trim((string) ($school['field'] ?? '')) !== '')
---
@endif
<< $school['location'] >>%
@endif
@if(trim((string) ($school['degree'] ?? '')) !== '' || trim((string) ($school['field'] ?? '')) !== '' || trim((string) ($school['location'] ?? '')) !== '')
\par
@endif
@endforeach
@endif
\end{document}
