# Finance Fact Library: house style for United States pages

Adds to core.md. Where the two differ, this file wins for US pages. Modelled on ca.md. Written 27 November 2026 from
Richard's decisions A to K in `docs/content-maps/us-content-map.md` (all taken as recommended). A "Reference page" section is
added once the first page (Minimum wage) is approved.

## Language

- American English: color, labor, favor, center, -ize (organize, recognize), license (noun and verb), defense, practice (noun and verb),
  check (a bank check), program. Use an official name exactly as written, whatever its spelling.
- Dates are written January 1, 2027, as the federal government writes them: month, day, comma, year. No ordinals ("1st"). Where such a
  date sits mid-sentence, put a comma after the year too ("On January 1, 2027, the limit rises"). "Tax year 2027" is fine.
- Numbers: a comma from four digits up ($4,210 and $18,200), never a space. Numerals for 2 and above, "one" in words.
- Money (decided 27 November 2026): the dollar sign goes straight in front of the numerals ($7,000), with no ".00" on whole dollars.
  Amounts under $1 are written in cents (15 cents). Percentages have no space (15%). The currency is the US dollar. Do not write
  "US$" unless a page compares the United States with another country, because Canada, Australia and New Zealand also write "$".
- Lists in a sentence take the serial comma ("A, B, and C"). This is the one place us.md differs from ca.md, because American official
  writing keeps it.
- Use double quotation marks. Don't use em dashes.
- Write ranges in words ("from January 1, 2027 to December 31, 2027", "between $10,001 and $20,000"), not with a dash.
- Official names exactly as the bodies write them. First mention on a page: the full name with its short form in brackets, then the
  short form: Internal Revenue Service (IRS), Social Security Administration (SSA), the Federal Reserve, Federal Deposit Insurance
  Corporation (FDIC), Department of Labor (DOL), Centers for Medicare & Medicaid Services (CMS), Federal Housing Finance Agency
  (FHFA). Schemes keep their official capitals: Social Security, Medicare, Federal Insurance Contributions Act (FICA) tax, Individual
  Retirement Account (IRA), Roth IRA, 401(k), Health Savings Account (HSA), Earned Income Tax Credit (EITC), Family and Medical Leave
  Act (FMLA). Never use another country's term for a US thing (not "pension" for a 401(k), not "ISA" for an IRA, not "HMRC" for the IRS).
- Text says "the United States" at first mention and "the US" or "US" after it, as `COUNTRY_WORDING` does.

## Tax year

- The calendar year, January 1 to December 31, written 2027 (`COUNTRY_TAX_YEARS` for `US` is already the single style).
- A return for tax year 2026 is filed in 2027. `{tax_year}` is the year of the figures, never the filing year. Say "tax year 2027", not
  "the 2027 tax return".
- `{tax_year}` shows the year of the page's figures (from their tax_year), or, if none has one, the year of today's date. Use the
  placeholder rather than typing a year. It fills from the page's latest card.
- Many figures are indexed and change on January 1. Others run on other dates: Social Security's cost-of-living adjustment is announced
  each October and paid from January, Medicare premiums and FHFA loan limits are announced in the autumn, and the Federal Reserve sets
  its rate at scheduled meetings. Go by what each source says and give the date the source gives.

## States and territories

- The 50 states and the District of Columbia set their own income tax (or none), minimum wage, leave rules, sales tax, transfer taxes and
  unemployment benefits. Federal pages say so in one sentence where it matters and name the state as the source does. Say nothing where a
  rule is the same everywhere.
- Regional figures use the sub-jurisdiction codes (`US-CA` for California and so on, added by migration 038).
- Pages are about the 50 states and DC. The territories (Puerto Rico, Guam, the US Virgin Islands, American Samoa and the Northern Mariana
  Islands) have different tax and benefit rules: do not describe them, and do not claim a rule holds "everywhere in the US" unless a source
  says so in those words.
- The federal minimum wage is a floor: where a state sets a higher rate, the higher rate applies. Take each state's treatment from the
  Department of Labor's state table, never from memory.

## Pages that change often or by law

- The 2025 tax law changed many tax rules and the IRS's pages still name it. Rules the government has changed or announced changes to
  (income tax rates, estate and gift tax, overtime, Medicare premiums) carry the most dated wording. State only what the cited page says,
  give the date from the page, and add the page to the key dates in `docs/TODO.md` when it is built.
- IRS "current year" pages can lag the IRS's own newer releases (the rates and brackets page showed 2025 in 2026). Read the release or the
  Revenue Procedure it cites, and where two sources disagree, the legislation or the second source. Never state the disputed detail.

## Sources

- Point to irs.gov, ssa.gov, federalreserve.gov, fdic.gov, dol.gov, cms.gov, medicare.gov or fhfa.gov only where there is something
  specific to do, such as filing a form, creating an online account or checking coverage.

## Rate pages

- The Federal Reserve's federal funds target range and any other rate checked every 42 days or less. See core.md, section 2.
- Call it the federal funds rate, or the target range for the federal funds rate, as the Fed does. The Federal Open Market Committee (FOMC)
  sets it at scheduled meetings: read the committee's name and the meeting calendar on the Fed's own page before writing them down.
