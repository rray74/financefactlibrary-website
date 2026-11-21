# Drafting loop: progress to 21 Nov 2026, and what comes next

This file summarises the "writing the subject pages" work: what was built, what it cost, where it stands and what to do next. It goes with `docs/api-costs.md` (key setup and costs) and `docs/page-checklist.md` (the per-page routine). It contains no key values.

Handover note for the move to Claude Code: Claude in the browser could not run PHP, so it syntax-checked files with a JavaScript PHP parser and Richard tested on the server and on a Mac (MAMP). Claude Code can run the code itself, so the checks in section 9 can be tested properly.

---

## 1. The goal

financefactlibrary.com is a PHP/MySQL/Tailwind site on Hostinger. Each UK reference page is built from figure cards (facts with sources) plus an AI-drafted Explanation. The aim is to finish a page **without needing Claude in the browser**, in about 25 minutes of Richard's time or less, at a low AI cost.

## 2. Where we started (26 to 28 Oct)

- Pension Tax Relief took hours and cost over $2.00. VAT took 45 minutes and about $2.30.
- The loop ran up to five checks and rewrites, which cost a lot and made the text worse. Rewrites broke the meta fields, and whole-text rewrites regressed.
- Richard's summary after Pension Tax Relief: several attempts in the background, still reliant on Claude in the browser, over $2 spent, and lessons still needed Claude to run.
- Decision on 28 Oct: "take a step backwards to take a step forwards". Build a cheaper, simpler loop.

## 3. What was built

All files are in the project, with explanatory comments.

| File | What it does |
|---|---|
| `includes/ai-drafts.php` | Writer. An 8-item checklist (`DRAFT_CHECKLIST`, including no figure history), a `<selfcheck>`, an optional coverage pass, and `reviseDraft()` taking extra fixes |
| `includes/ai-coverage.php` | Coverage pass. **Off** by default (`AI_COVERAGE_ENABLED`), as it did not reduce findings |
| `includes/ai-polish.php` | Polish loop. Two checks by default (`POLISH_MAX_CHECKS`, `AI_POLISH_MAX_CHECKS`), a stop rule, the best version kept, rewrite refusal rules (`polishRejects()`), `polishFieldsToChange()`, a 300-word floor (`POLISH_MIN_WORDS`), and per-check logging with a usage line |
| `scripts/run-polish-jobs.php` | Every-minute cron that runs polish jobs (also runnable by hand) |
| `scripts/draft-pages.php` | Nightly drafting. **Paused** (`DRAFTS_PAUSED = true`) |
| `includes/text-checks.php` | Program checks: near-copy of a source (60% word match), dating words, meta lengths (title 42 or fewer, description 140 to 155), a typed figure equal to a card's value, a rate in the meta description of rate pages. Includes a tolerant JSON salvage parser for AI replies |
| `includes/ai-fixes.php` | Propose fix. Up to 3 changes in any field, program-checked by `fixRejects()`, plus batch "Propose fixes for all findings" and "Apply all proposals" |
| `includes/ai-proposer.php` | `claudeUsageLedger()`, `claudeUsageText()`, `AI_PRICES`, and `anthropicApiKey()` with per-purpose keys |
| `includes/lessons.php`, `admin/lessons.php` | Lessons with fixed references (L1, L2, and so on) and "Edit the wording" in place |
| `admin/subject.php` | The editor: checklist panel, Check with AI, Propose fix, batch buttons and a cost message after each |
| `scripts/check-sources.php`, `scripts/check-page-text.php` | Daily and weekly source and page checks, billed to the SOURCES key |
| `docs/page-checklist.md`, `docs/api-costs.md` | The per-page routine, and the key and cost record |

The design change that mattered most: **rewrites were replaced by Propose fix** (the smallest change that fixes one finding, checked by program before it is offered). The loop now does less and the fixes are applied by Richard.

## 4. The per-page routine

1. Open a page from the Ready to write queue. Note the ffl-pages total in the Console, and the start time.
2. Claude gives pre-flight watch-outs for the page.
3. Read the draft and the AI check. Use Propose fixes for all findings, then Apply all proposals.
4. Paste the saved Explanation to Claude. Claude reviews it against the sources and the lessons, and returns a **full corrected Explanation** plus a meta description fix.
5. Save, then read the ffl-pages total again about an hour later. Claude works out the page's cost.

## 5. Rules that came out of this (lessons and house style)

House style is in `docs/house-style/core.md` and `uk.md`. Lessons identify by fixed reference, not number order:
- **L2, L13:** each point once. A section must add something the cards don't.
- **L9:** keep the source's hedge strength. Do not add "only".
- **L15:** spell out an abbreviation first.
- **L19:** own wording and own structure. Do not follow a source's sentence order.
- **L27:** say only what the sources say.
- **L28:** no "usually", "mostly" or "generally".
- **L32:** no figure history.
- **L34:** no typed changing figure without a card (a 2% typed in text is a finding).
- **L35:** no worked examples.
- **L38:** a placeholder already includes its unit ("35 years", "a mile"), so do not repeat the unit after it.
- **Rate pages:** a fact checked every 42 days or less. No rate in any field, not even as a placeholder, and no "rose" or "fell".
- **uk.md:** one sentence naming where the nations differ, with no figure.

## 6. Money

From `docs/api-costs.md` (Console, last 30 days, read 21 Nov 13:36 UTC):

| Key | Total |
|---|---|
| ff-website (retired) | $13.93 (read 12 Nov) |
| ffl-pages | $3.90 (about $0.41 of this was local Mac testing on Sonnet 4.6, now fixed) |
| ffl-sources | $1.91 |
| ffl-multicountry | $0.30 |
| Balance on 21 Nov | $4.94 |

- Per page since the loop was cut back: **about $0.43 average**, range $0.29 to $0.67 (Premium Bonds onward, 14 pages).
- Your time per page: 45 min (VAT) down to 5 to 8 min.
- Keys: ffl-pages is the default on the server, ffl-multicountry is the default on the Mac, ffl-sources is for the check scripts. `AI_KEY_PURPOSE` chooses the key per script.
- Model: `claude-sonnet-5-5` everywhere ($2 input and $10 output per million tokens).

## 7. Pages finished in this routine (16)

Pension Tax Relief, VAT Rates and Thresholds, Premium Bonds, Lifetime ISA, Corporation Tax Rates, Deposit Protection (FSCS), Buying Your First Home, Tenancy Deposits in England, Capital Gains Tax Rates and Allowance, Child Benefit Rates and the High Income Charge, Approved Mileage Rates, Minimum Pension Age, State Pension Amount, Cash ISA Allowance, Average Savings Rates, Buy-to-Let Mortgage Rates.

**Left: 30 pages without an explanation, 0 with a draft ready.** The queue by impressions starts: PIP and Attendance Allowance, Student Loan Repayment Thresholds, Auto-Enrolment Thresholds and Minimum Contributions, Standard Variable Rate (SVR), Employee and Employer National Insurance, Inheritance Tax Thresholds and Rates, Scottish Stamp Duty (LBTT) Rates, Income Tax Rates and Bands (England, Wales and NI), Statutory Holiday Entitlement, Personal Savings Allowance and Starting Rate, Dividend Tax Rates and Allowance, Employer Payroll Costs, Marriage Allowance, Inheritance Tax on Gifts, and others.

## 8. Problems met, and how they were fixed

- AI check reply could not be parsed: salvage parser, an 8,000-token ceiling and one retry.
- A run used old code because new files were not uploaded: confirmed with grep checks.
- A cron-started job vanished mid-run (cause not confirmed). Manual runs were fine.
- Rewrites broke the meta fields: cut to two checks, `polishFieldsToChange()`, and Propose fix replaced rewrites.
- Propose fix failed on cross-field fixes, near-copies and meta length: multi-field changes, source text on retries, meta-length warnings.
- The Mac leaked local testing onto ffl-pages (default key, Sonnet 4.6): fixed on 12 Nov.
- Claude's own errors, now guarded against: a wrong lesson number (now fixed L-references), a placeholder key suggested without being seen, facts removed that the checker's own sources included, a replacement meta description that created a finding, and wrong law added by a Propose fix (a stale-source "tenancy agreements from 1 June 2019" sentence, the Lifetime ISA fix). On Buy-to-Let, the AI's own proposed fixes kept "usually" and the saved text contradicted itself on the 36-month rule.

## 9. What is not solved

- **Your pass criterion is not met.** Richard still sends each page's text to Claude for review, and that review keeps finding things the checks miss.
- **The loop rarely meets its own stop rule.** The checker is noisy, partly because cited sources on some cards are out of date, so it cannot see current rules.
- **Recurring defects with no program check:** typed figures under 10 (the check ignores whole numbers below 10, so "2%" gets through), banned hedge words (L28), and a unit repeated after a placeholder (L38). A self-contradiction cannot be caught this way.
- **ffl-sources spend doubled** to about $0.25 a day on 20 and 21 Nov (cause unknown).
- **The balance** of $4.94 covers about 11 pages. The queue needs about $13.

## 10. Next steps, in order

1. **Three program checks: done (21 Nov 2026), in `includes/text-checks.php`.** All run inside `quickTextChecks()`, so the editor, the drafting loop and `fixRejects()` (through `draftScore()`) all see them, and Propose fix cannot reintroduce them.
   - *Small typed figures (L34):* `typedNumbers()` now counts a whole number under 10 when it is a percentage ("2%", "2 per cent") or money ("£5"). Plain small numbers ("Plan 2", "3 steps") are still ignored. The existing equal-to-card and rate-page rules then apply to it.
   - *Hedge words (L28):* "usually", "mostly", "generally" (`HEDGE_WORDS`). In the drafting loop and Propose fix, a hedge is flagged only if no source text contains it, because L9 says to keep a source's own hedge. In the editor's quick check (no sources read) it is flagged with "keep it only if a source says it".
   - *Unit repeated after a placeholder (L38):* `placeholderUnitRepeats()` works out what each placeholder shows. It flags "{fact:x} years" where it shows "35 years", "{fact:x} a mile", "{fact:x}%", and "£{fact:x}" where it shows "£...".
   - The draft checklist (`DRAFT_CHECKLIST`) tells the writer about all three up front.
2. **Investigate the ffl-sources spike**: cron, `check-sources.php` output, any extra manual runs on 20 and 21 Nov.
3. **Top up the balance** before continuing the queue.
4. **Keep nightly drafting paused.** Review time is the bottleneck, and drafts that wait go stale. If it resumes, set `DRAFTS_PER_NIGHT` to the real daily rate.
5. **Fix the admin queue wording.** It says the nightly job makes the next batch at 05:00 while `DRAFTS_PAUSED` is on.
6. **Before drafting rate-heavy or multi-nation pages** (Student Loan, Income Tax, LBTT, Inheritance Tax), check their cards and sources first. Student Loan, Income Tax and LBTT differ by nation, so they carry the most review.
7. Continue the queue one page at a time with the routine in section 4.

## 11. Open to-do list

**Figure cards and sources**
- Minimum Pension Age: add a second source for the 55 and 57 cards (HMRC Pensions Tax Manual PTM062200 onwards, or the GOV.UK abolition of the lifetime allowance), and rename the "now" label.
- Approved Mileage Rates: second source (job expenses claim). FSCS: second source (how protection works). Corporation Tax: second source (rates and reliefs). Buying Your First Home: second source (GOV.WALES LTT guide).
- Tenancy Deposits: change the penalty card to "1 to 3 times the deposit". Optional: a 60-day CGT property deadline card.
- Pension Tax Relief: Scottish extra-relief percentages. VAT: the £8,818 NI threshold.
- Cash ISA Allowance: update the card sources to the Sept 2026 newsletter 23 or the anti-circumvention factsheet, and revisit after 6 April 2027.

**Live-page checks**
- Premium Bonds: a typed £50,000 should be a placeholder, and the intro promises a deleted section.
- VAT NI paragraph. Lifetime ISA saved version. Council Tax Bands read and save. Carer's Allowance EEA fix. The 5 Nov base rate update CSV.

**Housekeeping**
- Disable the ff-website key in the Console.
- Add `define('AI_KEY_PURPOSE','MULTICOUNTRY');` to the multi-country scripts on the server.
- Do the 16 old drafts by hand.
- Update the stale "about ten minutes" comment in `run-polish-jobs.php`.

**Possible later builds**
- Proposed updates when a source changes. An AI check on lesson approval. A retired-lessons list and deletion (deletion is on hold, see below). Lower the 300-word floor on rate pages in `ai-polish.php`.

## 12. How Richard wants work delivered

- Share **full changed files**, with explanatory comments in the code.
- Say **"new" or "replacement"**, with the path from the project root.
- **Never paste a whole API key** anywhere. Last four characters only.
- Lessons are edited **in place**. **Deletion is on hold.**
- Per page, after the text is saved: the full corrected Explanation to paste, the meta description, and the page's cost.

## 13. Related conversations

The multi-country work (other countries' pages, the MULTICOUNTRY key) continues in its own chat. A separate handover was written for it.
