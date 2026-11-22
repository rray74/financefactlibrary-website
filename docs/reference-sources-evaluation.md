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
