# Finance Fact Library: house style (all countries)

This guide covers the four fields written for each subject page: the meta
title, the meta description, the intro and the explanation. It is read by
people editing pages and by the drafting tool ("Draft this for me"), which
sends it to the AI with every request. Each country also has its own file
in this folder (uk.md, and later us.md, ca.md, au.md, nz.md), which adds to
these rules and wins where the two differ.

The figure cards are not covered here. They come from the database, with
their own notes, sources and history.


## 1. General rules

**Facts, not advice.** Say what the rules are and how they work. Never tell
the reader what they should do, compare products or providers, or predict
what will happen. "Pension contributions can reduce adjusted net income" is
fine; "you should pay more into your pension" is not.

**Plain English, second person.** Write to the reader as "you". Short
sentences. Explain a technical term the first time you use it, and bold it
there and nowhere else.

**Official names.** Use the name of a benefit, tax or scheme as the
government writes it. Spelling follows the country file.

**Regions within a country.** Follow the country file for when to mention
regional differences.

**No words that date the text.** Never "currently", "this year", "now",
"recently", "new", "latest", "today" or "at the moment", including in a
contrast such as "not where it is today". A page is read for years.

**One country per page.** When the same subject exists in two countries
(minimum wage in the UK and in Australia, say), write each page from that
country's own rules. Never adapt one country's page into another's: the
rules usually differ in ways an adapted page misses, and near-copies look
mass-produced to search engines.


## 2. Figures in text

The figure cards update themselves. Text only does if you use a
placeholder:

    {fact:fact_key}   the figure from that fact's card, e.g. {fact:ca_weekly_rate} -> £86.45
    {tax_year}        the tax year the page's figures are for, e.g. 2026/27

The editor lists each page's placeholders with what they show, and clicking
one puts it where your cursor is. Use figures that appear on the same page.
Saving refuses a placeholder that doesn't exist or whose fact isn't
published.

**Figures that change (uprated every year, set by a rate decision, or
changed in a Budget): never type them.** Use the placeholder.

**Figures fixed in law until a known date: you may type them,** and give the
end date if one is known. "£12,570, frozen until April 2031" is fine.

**Numbers in a figure's label** (the 21 in "21 and over", the 2 in "Plan 2")
are part of the page already and may be typed without being flagged. Other
category numbers, such as an age that no label contains (apprentices under
19), may be typed too but are flagged for you to check, since bands do change.

**Figures you work out yourself** (such as the 60% effective rate between
£100,000 and £125,140 in the UK) are allowed when they follow directly from
fixed figures. Show how they're worked out in the same paragraph.

**Rate pages** (any page whose main figure is checked every 42 days or
less): don't state the rate in the text at all, not even as a placeholder,
and don't describe its direction ("rates have fallen"). Explain what the
rate is, what moves it and what it affects.

**No hand-written worked examples with numbers.** Pages that need them
generate their own from live figures.


## 3. Meta title

The headline in search results.

- Aim for 42 characters or fewer. " — Finance Fact Library" is added after it,
  and the whole title should fit in about 60. Longer is acceptable if the
  important words come first, since search engines cut from the end.
- Lead with what people search for: the subject's usual name, then
  `{tax_year}` if people search by year, then the headline figure as a
  placeholder if it's the answer they want.
- Name the country if the subject isn't unique to it ("UK Minimum Wage",
  but not "UK Personal Allowance").
- Example: `Carer's Allowance {tax_year}: {fact:ca_weekly_rate} a Week`
- No clickbait, no "ultimate guide", no exclamation marks.


## 4. Meta description

The snippet under the headline in search results.

- 140 to 155 characters, with figures filled in (the editor counts them
  that way).
- Answer the main question in the first half, using placeholders for
  figures. Use the second half to say what else the page covers.
- One or two sentences. Don't start with "Learn about" or "Find out".


## 5. Intro

The line under the page heading. It also appears on the subject's card on
the subcategory page, and is used as the meta description if that's blank,
so it must make sense on its own.

- One sentence, 120 to 200 characters.
- Say what the page covers, in the reader's terms. No figures.
- Example: "How much income you can have before paying Income Tax, how the
  allowance shrinks above £100,000, and the extra allowance for blind
  people." (The £100,000 is fixed, so it can be typed.)


## 6. Explanation

The main text, below the figure cards.

**Length.** Usually 300 to 600 words, and never more than 800. A page with
many figures does not need a long explanation: the figure cards show the
figures. Choose the few points readers most often get wrong, such as
exceptions, conditions and what does not count, and leave the rest to the
cards and the source pages. Never walk through the figures one by one. If a
subject truly needs more, it is two subjects.

**Structure.**
- 3 to 5 sections, each with a `##` heading that states the point ("Above
  £100,000, the allowance shrinks"), not a label ("Tapering").
- Start with how the thing works, in a paragraph or two.
- Then the parts people get wrong. They're the reason the page is worth
  reading, so give them their own headings, near the top.
- Then related allowances, exceptions and regional differences.
- Bullet lists only for genuinely separate items. Most of the text is prose.

**Don't:**
- repeat the card notes word for word (they're on the same page)
- end with a generic "What to check" or "Get advice" section; the site's
  disclaimer covers this
- link to a government site unless there's something specific to do there,
  such as claiming an allowance

**Format** (all the editor understands): `## Heading`, `### Smaller
heading`, `- bullet`, `1. numbered`, `**bold**`, `[link text](/uk/tax/)`,
and a blank line between paragraphs.


## 7. Before saving

The check on save will do this once it's built. Until then, check by hand:

1. Every typed figure is either fixed until a known date (given) or
   replaced by a placeholder.
2. No tax year or changing figure is typed into the meta title or
   description.
3. None of the dating words in section 1.
4. Regional differences are covered as the country file says.
5. Nothing tells the reader what to do.
6. Lengths: title about 42, description 140 to 155, intro 120 to 200
   characters; explanation usually 300 to 600 words, never over 800.
