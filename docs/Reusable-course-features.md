# Shared course tools

The application code is reusable across institutions. Names, curricula, subjects, student data, dates, passwords, logos and deployment aliases are not defaults in the shared engine.

## Private deployment configuration

`app/Config/branding.php`, `storage/` and the entire `content/` tree remain outside Git. Do not copy another installation's SQLite database or signing keys. New quiz tables and columns are migrated in place by `QuizDbService`; back up the existing database consistently before deployment (including SQLite WAL state).

The web PHP runtime needs `pdo_sqlite` for `/quiz` and `/quiz-admin`. A command-line PHP extension check alone does not verify the domain's web runtime. The shared `.htaccess` deliberately does not select a hosting-specific PHP version. Verify the three entry points `/`, `/quiz`, `/quiz-admin` after hosting changes.

## Student navigation and course portals

Optional branding keys:

```php
'student_contact_email' => 'teacher@example.org',
'display_site_address' => true,
'show_student_quiz_link' => true,
'portal_only_paths' => [],
'page_metadata' => [
    'institution/module' => ['title' => 'Module title', 'description' => 'Module description'],
],
```

The address banner uses `public_base_url`. Contact, banner and new portal components inherit the site's CSS variables. There is no institution-specific color scheme in these components.

An `_intro.html` or `_intro.md` in a content folder adds a course portal without renaming existing document URLs. HTML takes precedence. Shared CSS classes: `course-portal`, `portal-grid`, `portal-card`, `portal-links`. An explicitly listed `portal_only_paths` folder hides the automatic file listing and toolbar; the default retains them. Students should receive published presentations or support PDFs, not private slide preparation sources.

## Assessment registry

Create private `storage/assessments.json`. Missing configuration means no assessments are installed. Invalid configuration is rejected rather than silently disabling protection.

```json
{
  "version": 1,
  "assessments": {
    "EXAMPLE-ASSESSMENT": {
      "scope": "institution/module/assessment",
      "title": "Example assessment",
      "return": "/institution/module",
      "access": "protected",
      "submission": true,
      "platform": "github",
      "document": "README.md",
      "private_allowed": true,
      "deadline": "2027-01-15T17:00:00+01:00",
      "feedback": {
        "subject": "institution/module/assessment",
        "returns": "institution/module/feedback"
      }
    }
  }
}
```

Use `access: "public"` only for a deliberately public subject. Protected subjects cover direct previews, raw Markdown, downloads and archives of parent folders. Their private password record defaults to `storage/assessment-access/EXAMPLE-ASSESSMENT.json`, containing `{"password_hash":"..."}` generated with PHP `password_hash`. `access_record` and `access_session` can retain existing deployment records and grants during migration. A missing password record keeps the subject closed.

Submission forms accept GitHub or GitLab project URLs and retain the historical `github_url` field for stored-record compatibility. Receipts are stored in `storage/remises-cc/<id>/`; retries reuse the receipt. No file-upload functionality is implied.

## Review and feedback

Superadministrators access `/quiz-admin/remises`. Assessments with submission enabled and a deadline appear automatically. The UI supports search, filters, receipt history, deadline changes, private comments, review states and CSV export. Review writes use file locks and version checks. GitHub's public API check observes `main` and either `README.md` or `EXPLICATIONS.md`; it is not an automatic grade. GitLab submissions and inaccessible/private repositories require manual review; an unavailable API is not reported as a missing deliverable.

Feedback records remain private in `storage/assessment-feedback/<id>/results.json`, with version `1`, the assessment ID, and `results` keyed by the SHA-256 of the normalized email. Each value contains `student_id` and teacher-prepared `html`. The current explicit email lookup is rate limited and CSRF protected, but is not proof of identity. Enable it only for the intended publication policy; no feedback is imported with the code.

## Presentation order

`/presentation-draw` is available only when private `storage/presentation-draw/config.json` exists. Configure `event`, `launch_code_sha256`, and a list of 2–200 unique `{id,name}` teams. Optional `start_at` (ISO date/time with timezone) and `slot_minutes` add Paris-time schedule slots. The launch requires its code, CSRF and explicit confirmation. The server uses Fisher–Yates with `random_int`, stores one result under a lock, and returns that same result on retries. `?format=json` exports the stored result and checksum. This checksum detects accidental changes; it is not an independently signed attestation.

## Compatibility aliases

Private branding may define `redirects` as a list of `{from,to,prefix,status}`. Aliases preserve encoded paths, `+download` and query strings, apply only to GET/HEAD, and match path boundaries. They let a deployment retain previously distributed links without embedding its domains or course paths into the engine.

## Verification

Run PHP tests under `tests/*Test.php`, the two `assessment-review-*-test.php` scripts, JavaScript `tests/*Test.js`, and `python tests/AssessmentRoutesTest.py`. Tests use synthetic definitions and temporary storage. Check the Git index before committing: no `content/`, private branding, runtime records, database or credentials should be added. An independent deployment must keep its identity and data when receiving shared code.
