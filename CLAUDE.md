# Finance Fact Library (financefactlibrary.com)

A PHP + MySQL (MariaDB) site of country-by-country finance reference pages:
Category → Subcategory → Subject (page) → figure cards (facts with sources),
plus an AI-drafted, human-reviewed explanation per page. Tailwind CSS + DaisyUI.
UK is live, with all 53 UK pages written (24 Nov 2026); Australia launched 19 Nov 2026 and is
dripping out 3 pages a week (4 more pages still to build).
New Zealand, Canada and the US follow, each launching at about 15 to 20 pages.

## Stack, hosting, deploy
- Hostinger (site, database, admin app, cron). Local dev on MAMP at
  http://finance-fact-library:8888/.
- Deploy: VS Code → push to GitHub (`rray74/financefactlibrary-website`) →
  Hostinger auto-deploys over SSH on every push. Server app folder:
  `~/domains/financefactlibrary.com/app`.
- Never commit `config/database.local.php` or other credential files.

## Repo map
| Path | Purpose |
|---|---|
| `public/` | Web root: pages, `admin/` editor, `assets/` |
| `includes/` | Shared PHP: AI drafting/polish/fixes (`ai-*.php`), text checks, jobs (`jobs.php`, `job-handlers/`), placeholders, source fetching |
| `scripts/` | CLI scripts: `check-bank-rate.php`, `triage-page-text.php`, `import-reference-sources.php`, `import-facts.php`, `import-page-text.php`, `export-page-text.php`, `read-source.php`, `mark-page-reviewed.php`, `check-sources.php`, `check-page-text.php`, `run-jobs.php`, `run-polish-jobs.php`, `draft-pages.php`, `send-alerts.php`, `fetch-boe-rates.php`, `migrate.php` |
| `sql/migrations/` | Schema changes only. Next free number: **032** (check the folder before using) |
| `data/imports/` | Content CSVs (figures, page text) |
| `config/` | `database.php` plus `database.local.php` (secrets: never read or committed) |
| `docs/` | Handover, costs, house style, content maps |

## How content gets in
- Facts: CSV in `data/imports/` → `php scripts/import-facts.php <file>`.
  Reference sources (official pages a page's text rests on that no card cites):
  `import-reference-sources.php`; the AI check reads them with the card sources.
  Page text: `import-page-text.php` / `export-page-text.php`. Schema: migrations only.
- Always test on MAMP first, then run on the server over SSH. No phpMyAdmin edits.
  If the tooling can't do something, build the workaround.
- Read every figure live with `scripts/read-source.php` before it goes in a CSV.
- Page workflow (default, from 22 Nov 2026): Claude Code drafts the page (reads the sources live,
  writes the page-text CSV, imports on MAMP, runs triage and the near-copy check, reads every claim
  against its source) → human review → one commit per page → deploy and import on the server.
  The API route (draft and polish in the editor → AI check → Propose fixes) is the fallback.
  End to end: `docs/content-creation-process.md`; evidence: `docs/claude-code-drafting-test.md`.
  `scripts/mark-page-reviewed.php` records a person's review of imported text (dry run first).
- Keeping UK pages right: when a figure changes, update the CSV and re-import it, then re-check any page text that
  depends on it with `scripts/triage-page-text.php --page=<slug>`. Dated wording is listed in `docs/TODO.md`
  (key dates). Rate pages: no rate in any field, no rate placeholder, nothing about rising or falling.
  Per-page routine in `docs/page-checklist.md`. Start with the free triage
  (`php scripts/triage-page-text.php`) to rank pages by program findings;
  `--page=<slug>` also runs the near-copy check, `--close-copies` does it for every page.

## Cron and manual routines
- hPanel cron: `fetch-search-console` 06:00, `draft-pages` 05:00 (paused:
  `DRAFTS_PAUSED`), `check-page-text` 05:30, `check-sources` 07:30,
  `send-alerts` 08:00, `fetch-boe-rates` 07:00,
  `check-bank-rate` 13:00, 15:00 and 17:00 (acts only on MPC decision days; emails a draft CSV), `run-jobs.php` every 5 minutes
  (the Australia drip). Keep exactly one worker row.
- Bank of England base rate: after each MPC decision, update the Bank Rate CSV
  (use `data/imports/mortgages-boe-2026-11.csv` as the template) and run
  `import-facts.php`. The weekly job doesn't update it. The importer refuses a
  future `effective_from`, so run it on or after the announcement day.
  Next decision: 17 Dec 2026.

## AI keys (they cost money; no bulk AI jobs without asking)
Scripts choose a key with `define('AI_KEY_PURPOSE', ...)` before loading anything.
- `ffl-pages` (default on the server): editor, polish jobs, drafting.
- `ffl-multicountry` (`'MULTICOUNTRY'`; default on the Mac): multi-country work and local testing.
- `ffl-sources` (`'SOURCES'`): `check-sources.php`, `check-page-text.php`.
Every new AI script must set its purpose in its first lines. Model: `claude-sonnet-5-5`.
The old `ff-website` key is retired.

## Rules
- Secrets: never read, open, print or edit `config/database.local.php` or any
  credentials/.env-style file. Deny rules in `.claude/settings.json` block them; don't
  work around them. Ask if a value is needed.
- Facts: verify every figure against sources before handing it over. Don't ask
  Richard to re-check them.
- Content and database changes go through the tooling (CSV + `import-facts.php`,
  tested on MAMP first, then run on the server over SSH). Never suggest manual
  phpMyAdmin edits.
- Migrations: use the next free number in `sql/migrations`.
- Code changes: add explanatory comments where code changes.
- After any change, show each file's full path from the project root, say whether
  it is new or a replacement, in a table.
- Cost awareness: use the right `AI_KEY_PURPOSE`; run free checks (close-copy
  scoring, length checks) before paying for an AI pass.
- Commit messages are plain: no `Co-Authored-By` line (Richard's instruction, 21 Nov 2026).
- After Richard approves a page's text and it is deployed and imported on the server, stamp it reviewed with
  `php scripts/mark-page-reviewed.php` on the server, without asking again. Dry run first, and only for a page
  whose dry run says "was: never" (a time means he saved it in the editor; never overwrite it).
- Ask before anything hard to reverse: deploys, git pushes, server-side scripts,
  or live database changes.
- Give terminal commands one at a time and say which terminal (Mac or server).

## Docs (read on demand)
- `docs/TODO.md`: running to-do list and key dates.
- `docs/handover-2026-11-21-australia-launch.md`: Australia state, pipeline, gotchas.
- `docs/content-maps/au-content-map.md`: Australia plan, to-do, build log.
- `docs/drafting-loop-progress.md`: how the drafting loop works and what's next.
- `docs/api-costs.md`: key setup, wiring checks, spend.
- `docs/content-creation-process.md`: end-to-end process for creating content (start here).
- `docs/claude-code-drafting-test.md`: the test that made Claude Code drafting the default.
- `docs/page-checklist.md`: per-page finishing routine.
- `docs/house-style/core.md`, `uk.md`, `au.md`: writing rules (the drafting tool reads these).
