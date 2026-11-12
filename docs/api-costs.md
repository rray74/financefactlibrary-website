# AI costs and the API key setup

Last updated 12 Nov 2026. Figures are from the Claude Console (Cost & spend), in UTC, which lags by up to an hour. Costs are in US dollars.

This file explains which API key pays for what, how to check each part is wired correctly, and what the AI work has cost so far. It contains no key values: keep those only in `config/database.local.php`.

---

## 1. Why there are four keys

Until 5 Nov 2026 everything used one key, `ff-website`, so a page's cost could not be told apart from testing, the daily source checks or the multi-country work. Each purpose now has its own key. The Console's Cost page can then be filtered by **API key** to read each part's spend on its own.

Credits are shared. A separate key separates the *reporting*, not the balance: one balance pays for all four.

## 2. The keys

| Key | Ends | Used for | Config constant |
|---|---|---|---|
| **ffl-pages** | `JgAA` | The live site: the editor (Check with AI, Propose fix), `run-polish-jobs.php`, `draft-pages.php` | `ANTHROPIC_API_KEY` (the default) |
| **ffl-multicountry** | `GQAA` | Multi-country work and everything run on the Mac | `ANTHROPIC_API_KEY_MULTICOUNTRY` |
| **ffl-sources** | `jQAA` | `scripts/check-sources.php` and `scripts/check-page-text.php` (daily/weekly source and page checks) | `ANTHROPIC_API_KEY_SOURCES` |
| **ff-website** | `aQAA` | Nothing. Retired: no spend after 5 Nov. | none (disable it in the Console) |

All four sit in the Default workspace with no expiry.

## 3. What each environment's config holds

`config/database.local.php` (never committed; it holds the keys):

| Constant | Server | Mac (local MAMP) |
|---|---|---|
| `ANTHROPIC_API_KEY` | ffl-pages | **ffl-multicountry** |
| `ANTHROPIC_API_KEY_MULTICOUNTRY` | ffl-multicountry | ffl-multicountry |
| `ANTHROPIC_API_KEY_SOURCES` | ffl-sources | ffl-sources |
| `AI_DRAFT_MODEL` | `claude-sonnet-5-5` | `claude-sonnet-5-5` |
| `AI_PRICES` | set (see section 7) | optional |

The Mac's default key is the multi-country key on purpose: anything run locally bills to ffl-multicountry, and cannot touch the page-writing spend by mistake. (Before 12 Nov it was ffl-pages, which put about $0.41 of local testing on that key. See section 6.)

## 4. How a script chooses its key

`anthropicApiKey()` in `includes/ai-proposer.php` decides.

1. If the script defined `AI_KEY_PURPOSE` (for example `'SOURCES'`) **before loading anything**, it uses `ANTHROPIC_API_KEY_SOURCES`.
2. If there is no purpose, or no key set for that purpose, it falls back to `ANTHROPIC_API_KEY`. Nothing breaks if a key is missing; the cost just lands on the default key.

| Script | Purpose line at the top | Bills to |
|---|---|---|
| `check-sources.php`, `check-page-text.php` | `define('AI_KEY_PURPOSE', 'SOURCES');` | ffl-sources |
| Multi-country worker and any AI script written for the Australian build | `define('AI_KEY_PURPOSE', 'MULTICOUNTRY');` | ffl-multicountry (on the server) |
| Editor, `run-polish-jobs.php`, `draft-pages.php`, everything else | none | ffl-pages (default) |

Every new script that calls the AI should state its purpose in its first lines. On the server, a script without one bills to ffl-pages.

## 5. Checking the wiring

From the app folder (server) or the project folder (Mac). Each prints the last four characters of the key it picked; never paste a full key anywhere.

```
php -r 'require "config/database.php"; require "includes/ai-proposer.php"; echo "default: ", substr(anthropicApiKey(), -4), "\n";'
php -r 'define("AI_KEY_PURPOSE","MULTICOUNTRY"); require "config/database.php"; require "includes/ai-proposer.php"; echo "multicountry: ", substr(anthropicApiKey(), -4), "\n";'
php -r 'define("AI_KEY_PURPOSE","SOURCES"); require "config/database.php"; require "includes/ai-proposer.php"; echo "sources: ", substr(anthropicApiKey(), -4), "\n";'
```

Expected:

| Command | Server | Mac |
|---|---|---|
| default | `JgAA` | `GQAA` |
| multicountry | `GQAA` | `GQAA` |
| sources | `jQAA` | `jQAA` |

All six were confirmed on 12 Nov 2026.

## 6. What has been spent (to 12 Nov 2026, 20:58 UTC, last 30 days)

| Key | Total | Notes |
|---|---|---|
| ff-website | $13.93 | Everything before 5 Nov: building, testing and the first pages (Pension Tax Relief and VAT cost over $2 each before the loop was cut back) |
| ffl-pages | $1.84 | Child Benefit $0.52, Approved Mileage Rates $0.64, Minimum Pension Age $0.68. Of that, **about $0.41 was local testing** (Sonnet 4.6) billed here until the Mac's config was fixed on 12 Nov, so the page-only figure is about $1.43 |
| ffl-sources | $0.83 | Daily source and page checks, since 5 Nov |
| ffl-multicountry | $0.00 | Not yet used on the server |
| **All keys** | **about $16.60** | |

## 7. Cost of one page

A page has two parts. The **background job** (draft, AI check, one rewrite, second check) is reported in its own "AI use:" line at the end of the job note. The **editor work** is one Check with AI, Propose fixes for all findings, and Look for lessons. The editor messages after each Propose fix button also end with an "AI use:" line.

Per-page results (whole page; the job's own estimate is shown for comparison):

| Page | Date | Model | Whole page | Job estimate | Your time |
|---|---|---|---|---|---|
| Pension Tax Relief | 26-27 Oct | 4.6 and 5.5, old loop | over $2.00 | n/a | hours |
| VAT Rates and Thresholds | 27 Oct | 4.6 and 5.5, old loop | about $2.30 | n/a | 45 min |
| Premium Bonds | 28 Oct | 4.6 and 5.5 | about $0.61 | $0.31 | 33 min |
| Lifetime ISA | 28 Oct | 5.5 | about $0.55 | $0.30 | 13 min |
| Corporation Tax | 29 Oct | 5.5 | about $0.29 | $0.17 | 6 min |
| FSCS deposit protection | 29 Oct | 5.5 | about $0.35 | $0.17 | 6 min |
| Buying Your First Home | 29 Oct | 5.5 | about $0.40 | $0.24 | 6 min |
| Tenancy Deposits | 2 Nov | 5.5 | about $0.40 | $0.26 | 6 min |
| Capital Gains Tax | 4 Nov | 5.5 | about $0.45 | $0.26 | 8 min |
| Child Benefit | 5 Nov | 5.5 | **$0.52** (measured on ffl-pages) | $0.24 | 7 min |
| Approved Mileage Rates | 9 Nov | 5.5 | about $0.50 (upper bound) | $0.28 | 6 min |
| Minimum Pension Age | 11 Nov | 5.5 | about $0.41 | $0.23 | 5 min |
| State Pension Amount | 12 Nov | 5.5 | pending (baseline $1.84 on ffl-pages) | $0.32 | 6 min |

**Since the loop was cut back (Premium Bonds onward): average about $0.45 a page, range $0.29 to $0.61.** Pages with long drafts and many findings cost most, because each Propose fix attempt re-reads the source pages.

Prices (per million tokens, from Anthropic's pricing page, 27 Oct 2026), as set in `AI_PRICES`:

| Model | Input | Output |
|---|---|---|
| Claude Sonnet 5.5 (`claude-sonnet-5-5`) | $2.00 | $10.00 |
| Claude Sonnet 4.6 (`claude-sonnet-4-6`) | $3.00 | $15.00 |

## 8. Standing and projected costs

| Item | Cost |
|---|---|
| Daily source and page checks (ffl-sources) | about $0.10 a day, roughly $3 a month |
| Nightly drafting (paused; `DRAFTS_PAUSED`) | about $0.17 to $0.32 a page for the jobs alone, so about $0.75 to $0.95 a night at three pages |
| A new country at 15 to 20 pages | roughly $7 to $9 of AI cost, plus your time |

If nightly drafting is resumed, set `DRAFTS_PER_NIGHT` to the number of pages actually finished each day, because drafts that wait go stale.

## 9. Where the cost goes, and the levers

- **A job is about $0.17 to $0.32.** Each AI check and rewrite re-reads all the source pages. The loop is two checks and one rewrite by default (`AI_POLISH_MAX_CHECKS`, 1 to 5). It was five, which cost far more for little improvement.
- **The editor side is about $0.12 to $0.28.** A check is a few cents. Each Propose fix attempt is a few cents more.
- **The coverage pass is off** (`AI_COVERAGE_ENABLED`). It added a call and did not reduce the findings.
- **Model.** Sonnet 5.5 costs a third less per token than 4.6 and is now the default for drafting, rewrites and fixes (`AI_DRAFT_MODEL`).
- **Fewer re-checks.** One Check with AI per page, then stop; ignore style findings unless they are a typed figure, a stated rate or a dating word (see `docs/page-checklist.md`).

## 10. How to read costs in the Console

1. Cost & spend in the sidebar.
2. Filter **API key** to one key (or Group by API key), and set **Range**.
3. Hover a bar for the day's cost by model, or open the table view (icon at the top right of the chart) or the download arrow for a CSV.
4. A page's cost is the change in the **ffl-pages** total before and after it. Note the total before you start, and read it again about an hour after you finish.
5. The API keys list (Settings, API keys) shows each key's spend for the current month.

## 11. Open items

- Disable the **ff-website** key (no spend since 5 Nov).
- Add `define('AI_KEY_PURPOSE', 'MULTICOUNTRY');` to the multi-country worker and any AI script written for it, so they bill correctly on the server.
- Read the State Pension Amount cost: ffl-pages total minus $1.84.
- Optional: a separate Console workspace with a monthly spend limit for the multi-country key.
- Keep the balance topped up. It was $3.38 on 12 Nov, and one page costs about $0.45.
