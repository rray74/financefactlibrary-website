# Checklist for finishing a page

The same list is in the editor (Page details, "Checklist for finishing a page").
It is for a page that has been through "Draft and polish in the background".

Choosing which page: run `php scripts/triage-page-text.php` (free, read-only, no AI)
for a ranked list of every written page by program findings, with `--details` for
the findings and `--page=<slug>` for one page.
`--page=<slug>` also runs the near-copy check (it downloads the page's source pages:
the figure cards' and the reference sources'), so a clean `--page` result means no
sentence follows a source's wording at 60% or more. For the whole list add
`--close-copies` (about 35 seconds for 39 pages); `--no-close-copies` turns it off
for one page. A source page the site cannot read is shown as a finding, because
the check is then incomplete.

Aim: about 25 minutes from the finished job to a saved page, with no help from
Claude in the browser. If a page still needs that help, note which step it was
needed for.

1. **Draft.** Click "Draft and polish in the background" and run
   `php scripts/run-polish-jobs.php` (or wait for cron). Keep the job's
   "AI use" line. Open the page and click "Use draft" on all four fields.

2. **Rate pages.** A rate page is one whose figure is checked every 42 days or
   less (Premium Bonds, Base Rate, mortgage rates). No rate or odds in any
   field, not even as a placeholder, and nothing about it rising or falling.
   The "Checks on the saved text" box says so after you save.

3. **Typed figures.** A figure typed by hand that is also on a card (the VAT
   thresholds, the Premium Bonds holding limit) goes stale when the card
   updates and the text doesn't. Replace it with its placeholder. The checks
   list them. Leave it only if it is a different figure that happens to be the
   same, or is fixed in law until a date the text gives. A typed percentage or
   amount counts however small ("2%", "£5").
   The checks also flag "usually", "mostly" and "generally" (keep one only if a
   source page says it) and a unit typed after a placeholder that already shows
   it ("{fact:x} years" where it shows "35 years").

3b. **Reference sources.** If a claim in the text comes from an official page that no
   figure card cites (the freeze to 2031, a tax code, what claiming does to other
   benefits), add that page as a reference source: a row in a CSV for
   `php scripts/import-reference-sources.php --dry-run <file>` (see
   `data/imports/reference-sources-2026-11.csv`). The AI check, Propose fix and the
   drafting loop then read it, so correct text isn't flagged as unsupported.
   A page much longer than 50,000 characters (a Bank of England Monetary Policy
   Report is about 177,000) is cut there, so give the row an `excerpt_from` phrase
   from the page: it is read from just before that phrase, and the importer refuses
   a phrase that isn't on the page. `--list=<slug>` shows a page's reference sources. Read the page first with
   `scripts/read-source.php` and use only what it says.

4. **Check with AI once**, then **Propose fixes for all findings**. Read each
   proposal. Apply it only if the new words are true to the source, in your own
   words, and say nothing the sources don't. "Apply all proposals" applies every
   one shown; use it only after reading them.

5. **Substance findings** (contradicts a source, probably wrong, exception or
   condition missing, heading doesn't match, regional difference missing): fix
   them or decide the checker is wrong. **Style findings:** ignore them unless
   they are a typed figure, a stated rate or a dating word.

6. **Skip** a finding that asks for any of these, because the house style or a
   lesson forbids them:
   - a typed figure that changes (Scottish rates, thresholds with no card)
   - the history of a figure (when it changed, what it was)
   - a section that only repeats what the figure cards say

7. **Read the intro and meta description against the headings.** Nothing may be
   promised that the page doesn't cover; a topic the cards show counts as
   covered. Meta description 140 to 155 characters, meta title 42 or fewer, no
   Oxford comma (UK style). Those lengths are counted with the figures filled in.
   The database column also limits the text AS TYPED, placeholders counted as
   written: 200 characters for the description, 100 for the title. Over that is
   refused on save and by the importers (the database would cut it off mid-word,
   as it did on the Capital Gains Tax and Child Benefit descriptions), and a
   description of exactly 200 may already have been cut off.

8. **Save**, hard-refresh the live page (Cmd+Shift+R), then "Look for lessons".
   Approve a lesson only if it is general and doesn't contradict the house
   style. Lessons are edited in place ("Edit the wording" on the lessons
   page); deleting lessons is on hold.

9. **Note the time and the cost.** The job's "AI use" line, and the "AI use"
   line in the message after each Propose fix button, give tokens and an
   estimated cost (set AI_PRICES in config/database.local.php).

## When to stop

If you have run Check with AI more than twice, stop. The checker raises a
different point each run, and the stop rule allows a small tolerance
(no substance findings, at most two style findings, program checks clean).
