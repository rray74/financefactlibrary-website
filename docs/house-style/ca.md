# Finance Fact Library: house style for Canada pages

Adds to core.md. Where the two differ, this file wins for Canada pages. Modelled on nz.md. Written 25 November 2026 from
Richard's decisions A to F in `docs/content-maps/ca-content-map.md` (all taken as recommended). A "Reference page" section is
added once the first page (Minimum wage) is approved.

## Language

- Canadian English, as the federal government writes it: -our (colour, labour, favour), -re (centre), -ize (organize, recognize),
  licence (noun) and license (verb), practice (noun) and practise (verb), cheque, and "programme" only inside an official name.
  Use an official name exactly as written, whatever its spelling.
- Dates are written January 1, 2027, as the federal government writes them: month, day, comma, year. No ordinals ("1st"). Where
  such a date sits mid-sentence, put a comma after the year too ("On January 1, 2027, the limit rises"). "The 2026 tax year" is fine.
- Numbers: a comma from four digits up ($4,210 and $18,200), never a space. Numerals for 2 and above, "one" in words.
- Money (decided 25 November 2026): the dollar sign goes straight in front of the numerals ($7,000), with no ".00" on whole
  dollars. Amounts under $1 are written in cents (15c). Percentages have no space (15%). The currency is the Canadian dollar. Do
  not write "CA$" unless a page compares Canada with another country, because the United States also writes "$".
- Lists in a sentence take no comma before the last "and" ("A, B and C"), except where the last item contains its own "and".
- Use double quotation marks, as Canadian official writing does. Don't use em dashes.
- Write ranges in words ("from January 1, 2026 to December 31, 2026", "between $10,001 and $20,000"), not with a dash.
- Official names exactly as the bodies write them. First mention on a page: the full name with its short form in brackets, then the
  short form: Canada Revenue Agency (CRA), Employment and Social Development Canada (ESDC), Service Canada, the Bank of Canada,
  the Canada Deposit Insurance Corporation (CDIC), the Canada Mortgage and Housing Corporation (CMHC). Schemes keep their official
  capitals: Canada Pension Plan (CPP), Old Age Security (OAS), Guaranteed Income Supplement (GIS), Employment Insurance (EI),
  Tax-Free Savings Account (TFSA), Registered Retirement Savings Plan (RRSP), First Home Savings Account (FHSA), Canada Child
  Benefit (CCB), Canada Groceries and Essentials Benefit (CGEB). Never use another country's term for a Canadian thing (not
  "pension" for RRSP, not "ISA" for TFSA, not "IRS" for CRA).
- Pages are in English only. French names of bodies are not added.

## Tax year

- The calendar year, January 1 to December 31, written 2026 (`COUNTRY_TAX_YEARS` for `CA` is already the single style).
- `{tax_year}` shows the year of the page's figures (from their tax_year), or, if none has one, the year of today's date. Use the
  placeholder rather than typing a year. It fills from the page's latest card, so a page with a card for a future year (the EI table
  already lists 2027) would show the later year: leave the year out of the title in that case.
- Many figures are indexed and change on January 1. Some run on other dates: the Canada Child Benefit and the Canada Groceries and
  Essentials Benefit payment years run July to June, and Old Age Security is indexed quarterly. Go by what each source says and give
  the date the source gives.

## Provinces and territories

- Provinces and territories set their own income tax, minimum wage, leave rules and land transfer taxes. Federal pages say so in
  one sentence where it matters and name the region as the source does. Say nothing where a rule is the same everywhere.
- Regional figures use the sub-jurisdiction codes (`CA-ON` for Ontario and so on, added by migration 035).
- **Quebec** runs its own pension (the Quebec Pension Plan) and parental plan (QPIP), so any page on the Canada Pension Plan or
  Employment Insurance says where Quebec differs, using only what the source says. Do not describe Quebec's plans beyond that.
- HST is a combined federal and provincial tax, and PST and QST are provincial. Take each province's treatment from the CRA's
  table, never from memory.

## Pages that change often or by law

- Rules the government has changed or announced changes to (capital gains, the Canada Groceries and Essentials Benefit, mortgage
  insurance rules, tax rate changes) carry the most dated wording. State only what the cited page says, give the date from the page,
  and add the page to the key dates in `docs/TODO.md` when it is built.
- The GST/HST credit was renamed the Canada Groceries and Essentials Benefit as of July 2026. Do not describe the old name as current.

## Sources

- Point to canada.ca, bankofcanada.ca, cdic.ca or cmhc-schl.gc.ca only where there is something specific to do, such as opening an
  account, applying for a benefit or checking contribution room (My Account).

## Rate pages

- The Bank of Canada's policy interest rate and any other rate checked every 42 days or less. See core.md, section 2.
- Call it the policy interest rate, as the Bank does. The Bank's Governing Council sets it at scheduled announcements: check the
  Council's name and the schedule on the Bank's own page before writing them down.
