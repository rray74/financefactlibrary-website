# Finance Fact Library: house style for New Zealand pages

Adds to core.md. Where the two differ, this file wins for New Zealand pages. Modelled on au.md. Written
23 November 2026 from Richard's decisions in `docs/content-maps/nz-content-map.md`. Points marked
**(provisional)** are still open there and are written the way the plan proposes. Change them here once decided.

## Language

- New Zealand English, as the government writes it: British spellings (organise, realise, centre, colour),
  and "programme" for a programme of work or a scheme, unless an official name spells it another way
  (use the official name as written). Two to watch: licence (noun) but license (verb), and practice (noun)
  but practise (verb).
- Dates are written 1 April 2027: no ordinals ("1st"), no comma between the month and the year.
- Numbers: use a comma from four digits up (4,210 and $18,200), never a space. Use numerals for 2 and above
  ("3 weeks"), and write "one" in words.
- Money **(provisional)**: the dollar sign goes straight in front of the numerals ($1,000), with no ".00" on
  whole dollars, as the Australian pages do. Write amounts under $1 in cents (15c). Percentages have no space
  (15%). Round amounts of a million or more can mix numerals and words ($2.5 million). The currency is the New
  Zealand dollar. Do not write "NZ$" unless a page compares New Zealand with another country.
- Lists in a sentence take no comma before the last "and" ("A, B and C"). The exception is a list whose last
  item contains its own "and": put the comma before it, so the reader can see where the items split.
- Use single quotation marks. Don't use em dashes.
- Write ranges in words ("from 1 April 2026 to 31 March 2027", "between $10,001 and $20,000"), not with a dash.
  The one use of an en dash is a tax year (see below).
- Te reo Māori names keep their macrons wherever the source writes them (Kāinga Ora, Manawatū-Whanganui,
  Te Whatu Ora). Do not add a macron the source leaves out, and do not take one away. Slugs and file names
  stay in plain letters.
- Name schemes and bodies as the source does: Inland Revenue (**provisional:** write "Inland Revenue" at first
  mention and "IRD" only where the source page does, since readers search for both), Work and Income (part of
  the Ministry of Social Development), Employment New Zealand, Tenancy Services, the Reserve Bank of New
  Zealand, the Accident Compensation Corporation (ACC). Schemes and payments keep their official capitals:
  KiwiSaver, New Zealand Superannuation (NZ Super is fine after the first mention), Jobseeker Support,
  Working for Families, Best Start. Never use another country's term for a New Zealand thing (not "super",
  "pension" or "retirement account" for KiwiSaver, not "welfare" for a benefit).

## Tax year

- Runs from 1 April to 31 March, written 2026–27: four digits, an unspaced en dash, then two digits (decided
  23 November 2026, as Australia writes its year; `COUNTRY_TAX_YEARS` for `NZ` is set to the dash style).
- `{tax_year}` shows the year of the page's figures (from their tax_year), or, if none of them has one, the
  tax year of today's date, written that way. Use the placeholder rather than typing a year. As on other
  pages, it fills from the page's latest card, so a page with a card for a future April would show the later
  year: leave the year out of the title in that case.
- Say what the period is the first time a page mentions one, so a reader doesn't take 2026–27 for a range:
  "2026–27 tax year". IRD also writes "the 2027 tax year" for the year ending 31 March 2027: do not copy that
  form, but do not "correct" a source's wording inside a quotation either.
- Many figures change on 1 April. Some run on another date: the KiwiSaver government contribution year runs
  1 July to 30 June, and some payments and rules change on other dates. Go by what each source says and give
  the date the source gives.

## Regions

- New Zealand has no states or provinces and no regional income tax, so most pages need no regional section.
  Where a figure differs by region (price caps, accommodation areas, anniversary days), say so in a sentence and
  name the region as the source does. Say nothing where a rule is the same everywhere, unless readers often
  assume otherwise.
- Regional figures use the sub-jurisdiction codes (`NZ-AUK` for Auckland and so on, added by migration 032).
  Read the source's own list of regions before assigning codes: it may group places differently from the
  sixteen regions.

## No capital gains tax

- Write what the source says and no more. Do not state in text that New Zealand "has no capital gains tax"
  unless the page being written cites a source that says so in those words. Say what the rule is: for example,
  the bright-line test taxes some sales of residential property, and the page describes the test and its
  dates as IRD does.
- Do not use "capital gains tax" in a title or a heading for a page that is not about it, and read the plan's
  open decision on search wording before adding it to a title.

## Pages that change often or by law

- Rates and rules that the government has changed or announced changes to (the bright-line period, the
  KiwiSaver contribution rates and government contribution, tenancy rules) carry the most dated wording. State
  only what the cited page says, give the date from the page, and add the page to the key dates in
  `docs/TODO.md` when it is built.
- The Work and Income benefit rates page has the year in its address, so a card citing it needs a new source
  address every April.

## Sources

- Point to ird.govt.nz, workandincome.govt.nz, employment.govt.nz or tenancy.govt.nz only where there is
  something specific to do, such as claiming, applying or lodging a bond.

## Rate pages

- The Official Cash Rate and any other rate checked every 42 days or less. See core.md, section 2.
- Call the Reserve Bank's rate the Official Cash Rate (OCR), as the Bank does, and not the "cash rate target"
  (that is the Reserve Bank of Australia's term). The Bank's Monetary Policy Committee sets it, which is the
  kind of thing a rate page can say about what moves it. Check the committee's name and the decision
  schedule on the Bank's own page before writing it down.

## Reference page

- To be added once the first page (Minimum wage, `minimum-wage-new-zealand`) is approved, as au.md did after
  the tax-free threshold page.
