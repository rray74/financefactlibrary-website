# Checklist for finishing a page

The same list is in the editor (Page details, "Checklist for finishing a page").
It is for a page that has been through "Draft and polish in the background".

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
   same, or is fixed in law until a date the text gives.

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
   Oxford comma (UK style).

8. **Save**, hard-refresh the live page (Cmd+Shift+R), then "Look for lessons".
   Approve a lesson only if it is general and doesn't contradict the house
   style. Lessons can't be edited: retire the old one and add the new one.

9. **Note the time and the cost.** The job's "AI use" line, and the "AI use"
   line in the message after each Propose fix button, give tokens and an
   estimated cost (set AI_PRICES in config/database.local.php).

## When to stop

If you have run Check with AI more than twice, stop. The checker raises a
different point each run, and the stop rule allows a small tolerance
(no substance findings, at most two style findings, program checks clean).
