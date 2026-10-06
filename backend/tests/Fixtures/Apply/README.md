# Apply adapter DOM fixtures

Hand-authored representations of each ATS's application markup. **These are not
captures.** Nothing here was fetched from a live job board: every file was written
by hand from each vendor's publicly documented/observable form structure (field
names, `data-automation-id` conventions, modal class names), reduced to the parts
the adapters actually target.

They exist because of a gap the worker-level tests cannot close. Those tests fake
the automation worker, and the fake reports back `ok` for whatever step it was
handed — so a selector typo in `config/apply.php` passes every one of them and
only fails in production, against a real form, as a step timeout.

`tests/Unit/Services/Apply/ApplyFixtureSelectorTest.php` resolves each adapter's
configured selectors against these documents, so selector rot fails a test
instead of an apply run.

## Layout

```
{greenhouse,lever,workday,linkedin}/
  posting.html       the job description page, where the flow starts (Workday, LinkedIn)
  form.html          the application form (LinkedIn: modal.html)
  confirmation.html  the post-submit page/banner
  wall.html          a CAPTCHA or sign-in wall — the Req 9.6 dead end
```

## Rules for editing

- **No live fetching.** If an ATS changes its markup, hand-edit the fixture to
  match what was observed and say so in the commit message.
- Keep them minimal. Only markup an adapter selector targets, plus enough
  structure (wrappers, labels) for the selectors to be meaningfully exercised.
- One element per field. The harness asserts that a field/upload/submit selector
  resolves to **exactly one** element, which is what makes a typo detectable;
  adding a second plausible match turns a real assertion into noise.
- Wall fixtures must contain the wording, `<title>` and (where relevant) URL
  shape a real wall uses, because that text is what
  `AbstractApplyAdapter::blockedDetection()` matches against.
- No real candidate data. Everything is Ada Lovelace at `example.test`.
