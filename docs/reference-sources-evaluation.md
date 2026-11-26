# Did reference sources reduce false AI findings? (evaluation, started 21 Nov 2026)

## The question

Reference sources (migration 030/031, `scripts/import-reference-sources.php`) let the AI check read
official pages that support a page's text but that no figure card cites. They were built because the
AI check kept flagging correct text as unsupported. This file records the AI check results BEFORE
(the last check under the old rules) so they can be compared with the first check AFTER, on the same
pages. The AI check is `scripts/check-page-text.php` (daily 05:30, 5 pages a night, oldest-changed first).

Each page's text was also rewritten on 21 Nov (see the commits), so a drop in findings is partly the
rewrite and partly the sources. The cleanest signal is the KIND of finding: "contradicts_source",
"likely_wrong" or an "unsupported" house-style finding that disappears is the sources working; a new
finding of any kind is worth reading.

## Before (read over SSH, 21 Nov 2026, from `subject_text_checks`)

| Page | Last AI check | Findings | Kinds |
|---|---|---|---|
| Income Tax Personal Allowance | 19 Oct | 0 | none |
| Bank of England Base Rate | 6 Nov | 3 | copied_from_source 1, house_style 1, likely_wrong 1 |
| Carer's Allowance | 11 Nov | 5 | copied_from_source 4, house_style 1 |
| Capital Gains Tax Rates | 19 Nov | 3 | house_style 3 |
| Buy-to-Let Mortgage Rates | 20 Nov | 4 | contradicts_source 1, duplicated 1, heading_mismatch 1, house_style 1 |
| 2-Year Fixed Mortgage Rates | 19 Oct | 0 | none |
| Pension Tax Relief | 28 Oct | 0 | none |
| State Pension Amount | 13 Nov | 1 | copied_from_source 1 |
| Child Benefit | 6 Nov | 5 | duplicated 1, house_style 4 |
| Approved Mileage Rates | 10 Nov | 1 | house_style 1 |

Findings I judged to be false alarms caused by a source the check could not see (so the sources
should remove them): Carer's Allowance "usually lose the severe disability premium"; Child Benefit
"under 12" age limit; Approved Mileage Rates commuting sentence; Base Rate "usually"/pass-through;
Buy-to-Let Bank Rate pass-through.

## After, first batch (the 05:30 run on 22 Nov 2026: five pages re-checked)

| Page | Before | After | Kinds after | My read of the new findings |
|---|---|---|---|---|
| Bank of England Base Rate | 3 | **0** | none | The three false alarms are gone. This is the clearest win. |
| Carer's Allowance | 5 | 3 | house_style 1, missing_exception 2 | The false "usually lose the premium" finding is gone. New: spell out EU/EEA (real, L15, and the source names Switzerland, Norway, Iceland, Liechtenstein); State Pension "cannot build up extra State Pension" (real but minor); "Pension Credit increases" (already covered, ignore). |
| Capital Gains Tax Rates | 3 | 4 | heading_mismatch 1, house_style 1, missing_exception 2 | Mostly real and found only because the 60-day page is now read: heading should say "residential" (real); late payment interest and penalty (real); non-UK residents must report even with no tax due (real nuance); "unused allowance is lost and cannot be carried forward" is NOT on the GOV.UK allowances page, so it is unsourced (real, L27). |
| 2-Year Fixed Mortgage Rates | 0 | 4 | house_style 1, missing_exception 1, unneeded_section 2 | Real: the Scotland "standard security" sentence sits under the wrong heading and has no source the check can read (the Registers of Scotland page returns HTTP 403 from the server); "why a 5-year fix is shown" only restates the card (L13); "can move before Bank Rate does" is the writer's inference (L27); BoE averages use each lender's most competitive matching product, not a plain average. |
| Pension Tax Relief | 0 | 3 | missing_exception 3 | Real but small: the annual allowance covers all pensions together and counts payments by you or anyone else; carry forward and who pays the charge; the taper has a floor. The ill-health exception is too rare to add. |

Not yet re-checked (waiting in the nightly queue of 5): Income Tax Personal Allowance, Buy-to-Let,
State Pension Amount, Child Benefit, Approved Mileage Rates, Average Savings Rates, Corporation Tax.

### What this shows so far

- Reference sources DO remove false alarms caused by a source the check could not see (Base Rate 3 to 0,
  and the Carer's Allowance "usually" finding).
- They also let the check see more, so it raised real omissions it could not have raised before
  (Capital Gains Tax: three findings that come from the 60-day page). Counting findings alone
  understates the benefit: the new findings are mostly true.
- Two pages that had 0 findings now have 3 to 4. Neither is a regression in the text: the check found
  things I had not, and a second pass is warranted on those pages.
- The checker is not stable run to run (see docs/page-checklist.md, "When to stop"), so treat one
  run as a sample, not a verdict.

### Also confirmed on 22 Nov

The 07:00 `fetch-boe-rates.php` run rewrote the card notes from the new wording: "including unconditional
bonuses. Individual accounts can pay more or less." on the easy access ISA and instant access cards, and
the reversion-rate wording on the SVR card.

## Second pass (22 Nov 2026): acting on the first batch's findings

Each real finding was fixed or answered, one commit per page, and imported on the server.

| Page | Commit | What was done |
|---|---|---|
| Capital Gains Tax Rates | a6da952 | Heading says "residential". Added late interest and penalty, and the UK resident and non-resident reporting rules. Sourced the unused allowance rule to HMRC's Capital Gains Manual CG18000 (new reference source) |
| 2-Year Fixed Mortgage Rates | 982dcd1 | Described how the Bank builds the averages (most competitive product, first-time-buyer products excluded). Cut the writer's own inference, the section that restated the 5-year card, and the Scotland sentence. Page now 408 words |
| Carer's Allowance | 885506e | Named the countries under the EU Settlement Scheme (spelling out EU). Added that you cannot build up extra State Pension by delaying your claim. Page now 775 words, near the 800 limit |
| Pension Tax Relief | aa8ca53 | Rewrote the annual allowance section from the GOV.UK annual allowance page: all pensions together, what counts per scheme type, who pays the charge, carry-over, taper floor. Heading widened |

Judged not worth acting on: Carer's Allowance "Pension Credit increases" (already covered); Pension Tax Relief
ill-health and death exceptions to the charge (too rare).

The second-pass text has not been re-checked by the AI yet. The 05:30 run will pick these pages up again
("text changed since its last check"), so a second set of results will show whether the findings fall
further or the checker simply raises different points (it is not stable run to run).

### Lessons for the routine

- Check the 60-day / "which pages does this rule cover" scope in headings: two headings overstated
  ("UK property" for UK residential property; "Scotland" over text that also covered Wales).
- The 200-character meta description limit caught two live pages (Capital Gains Tax and Child Benefit); guard added in 83811c9.
- A source the SERVER cannot read (Registers of Scotland returns HTTP 403) cannot be used as a reference
  source, so a claim that rests on it is better cut than left unsupported.
- Long pages need `excerpt_from` (migration 031) or the passage is never read.

## Third pass (22 Nov 2026, evening): every page rewritten, none re-checked yet

**Status.** No new AI results exist beyond the first batch above. The 05:30 run on 22 Nov re-checked five pages (those in the first-batch table) before any of the later rewrites. Since then all 23 written UK pages and all 15 Australian pages have been rewritten, so `check-page-text.php --dry-run` on the server reports **38 written pages, 38 needing a check ("text changed since its last check")**, 5 a night. The first five to run, at 05:30 on 23 Nov, are 2-Year Fixed Mortgage Rates, Pension Tax Relief, National Minimum Wage and National Living Wage, Carer's Allowance and Premium Bonds. The queue clears in about 8 nights (to roughly 30 Nov). Add each night's results below as they arrive; one night is a sample, not a verdict.

**What changed between the stored checks and now**
- Every UK page had the claim-by-claim routine, and the near-copy check now runs in the triage, so no page should return a `copied from source` finding (the program, not the AI, finds those, and each page was reworded until it found none).
- Reference sources went from 13 pages to 23 pages (46 sources), including the first nine Australian ones (`fairwork.gov.au`, `ato.gov.au`, `servicesaustralia.gov.au`), so claims the card sources did not support can now be seen by the check.
- Australian pages are checked for the first time with their sources: six have **never** had an AI check (parental-leave-pay, personal-and-carers-leave, preservation-age, super-contribution-caps, super-guarantee-rate, tax-free-threshold), so those have no "before".

**What to look for in the new results**
- A `contradicts source`, `likely wrong` or `missing exception` finding is worth reading. These are the kinds that were real errors on the pages rewritten (compassionate leave shown unpaid, the Parental Leave Pay pre-birth rule reversed, the Medicare levy described as always charged).
- `house style` and `unneeded section` findings from the old checks are mostly fixed by the rewrites. A finding that comes back is a sign the rewrite did not address it.
- `copied from source` should be 0. If it is not, the AI's close-copy check and the program's disagree and that is worth a look.
- Judge by kind, not count: the checker is not stable run to run, and sources it can now see let it raise real omissions.

**Other data points from 22 Nov**
- `check-sources.php` (07:30): 344 figures found, 0 not found, AI 0 published, 0 held, 0 pages failed. That is up from 302 on 13 Nov (the Australian figures and the later UK ones), with no AI spend on it.
- `check-bank-rate.php` ran 13:00, 15:00 and 17:00 and correctly did nothing (not an MPC decision day).
- Cost: my estimate is roughly $0.05 a page, so about $2 for all 38 over the 8 nights. The real figure is in the Console (`ffl-sources`); the daily total should show the backlog clearing.

**Baseline: the stored AI check for each page before the 21 to 22 Nov rewrites** (the five pages re-checked on 22 Nov are in the first-batch table above).

**UK**

| Page | Last check | Findings | Kinds |
|---|---|---|---|
| approved-mileage-rates | 2026-11-10 | 1 | house style 1 |
| average-savings-rates | 2026-11-19 | 2 | house style 1, unneeded section 1 |
| buy-to-let-mortgage-rates | 2026-11-20 | 4 | contradicts source 1, duplicated 1, heading mismatch 1, house style 1 |
| buying-your-first-home | 2026-10-30 | 3 | copied from source 1, missing exception 2 |
| cash-isa-allowance | 2026-11-17 | 3 | contradicts source 1, copied from source 1, missing exception 1 |
| child-benefit | 2026-11-06 | 5 | duplicated 1, house style 4 |
| corporation-tax-rates | 2026-10-30 | 2 | contradicts source 1, house style 1 |
| council-tax-bands | 2026-11-06 | 3 | house style 2, missing exception 1 |
| deposit-protection-fscs | 2026-11-17 | 3 | house style 1, likely wrong 1, missing exception 1 |
| income-tax-personal-allowance | 2026-10-19 | 0 | none |
| lifetime-isa | 2026-10-31 | 5 | copied from source 2, heading mismatch 1, house style 1, missing exception 1 |
| minimum-pension-age | 2026-11-12 | 3 | contradicts source 2, house style 1 |
| minimum-wage-rates | 2026-10-30 | 11 | contradicts source 1, copied from source 5, duplicated 1, heading mismatch 1, likely wrong 1, missing exception 1, unneeded section 1 |
| premium-bonds | 2026-11-18 | 3 | heading mismatch 1, house style 1, missing exception 1 |
| state-pension-amount | 2026-11-13 | 1 | copied from source 1 |
| tenancy-deposits | 2026-11-06 | 3 | contradicts source 1, house style 1, missing exception 1 |
| universal-credit-rates | 2026-11-11 | 1 | copied from source 1 |
| vat-rates-and-thresholds | 2026-10-28 | 5 | copied from source 1, house style 3, likely wrong 1 |

**Australia**

| Page | Last check | Findings | Kinds |
|---|---|---|---|
| age-pension | 2026-11-20 | 3 | house style 2, missing exception 1 |
| annual-leave-australia | 2026-11-20 | 6 | contradicts source 1, house style 4, unneeded section 1 |
| cash-rate-target | 2026-11-20 | 8 | contradicts source 2, copied from source 1, duplicated 1, house style 3, likely wrong 1 |
| first-home-super-saver-scheme | 2026-11-20 | 6 | advice 1, duplicated 1, house style 3, missing exception 1 |
| gst-registration-threshold | 2026-11-21 | 7 | copied from source 1, heading mismatch 1, house style 3, missing exception 2 |
| income-tax-rates-australia | 2026-11-21 | 4 | contradicts source 1, house style 1, missing exception 1, unneeded section 1 |
| jobseeker-payment | 2026-11-21 | 8 | contradicts source 1, copied from source 1, duplicated 1, house style 2, missing exception 2, unneeded section 1 |
| medicare-levy | 2026-11-21 | 1 | house style 1 |
| minimum-wage-australia | 2026-11-21 | 4 | contradicts source 1, copied from source 1, duplicated 1, missing exception 1 |

## Claude Code pages, first nightly check (25 and 26 Nov 2026)

Ten of the 30 pages written in Claude Code on 22 to 24 Nov have been checked, with their reference sources read (every source read on the 26 Nov batch). 50 findings on 10 pages, 5 a page, close to the 4.8 a page of the API-drafted baseline (105 on 32 pages). About half needed a text change, a higher share than the old baseline (about 8 real in 105), but none was a wrong figure: they were overstated headings, missing conditions, one count the source does not give, and repeats. Reference sources let the check raise exact conditions (the £10,000 Self Assessment point, the LBTT repayment rule, the taper basis) that it could not have seen from the card sources alone. Detail and kinds of finding: `docs/claude-code-drafting-test.md`, "Nightly check on the Claude Code pages".

