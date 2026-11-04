-- 024_australia_source_urls.sql
--
-- Corrects the Australian source allowlist added in 023. When each page was
-- fetched on 4 November 2026 to read a figure from it, three of the ATO
-- addresses in 023 turned out to be old-style paths that the ATO now forwards
-- to a different address. A source must carry the page's real address, so
-- that the page the weekly check reads and the page a CSV cites are the same
-- row. Two other rows were only seen in a search result, never fetched, so
-- they are removed until they are confirmed.
--
-- This is a separate migration, and not an edit to 023, so that it is safe
-- whether or not 023 has already been applied on a given database. Applied
-- after 023, it leaves 14 Australian sources.
--
-- CORRECTED (the old address forwards to the new one)
--   Tax rates, Australian resident   now at /tax-rates-and-codes/tax-rates-australian-residents
--   CGT discount                     now at .../capital-gains-tax/calculating-your-cgt/cgt-discount
--   Contributions caps               now at /tax-rates-and-codes/key-superannuation-rates-and-thresholds/contributions-caps
--                                    This is a page of its own. The old address ended in
--                                    ?page=2, which was only the ATO's way of showing a section
--                                    of the long document, and the new page needs no suffix.
--
-- REMOVED UNTIL CONFIRMED
--   The ATO preservation age table (old address ended in ?page=4). Its new
--   address was not seen, and the page is not needed until the preservation
--   age page is built.
--   Housing Australia node/5990. It is an internal address that was not
--   fetched, and the page with the price caps table has not been identified.
--
-- No row has a figure attached yet, so nothing that cites these is affected.
-- No semicolons appear inside the comments or text in this file, so it is
-- safe for any runner that splits a migration into statements on semicolons.

UPDATE `sources`
SET `url` = 'https://www.ato.gov.au/tax-rates-and-codes/tax-rates-australian-residents'
WHERE `url` = 'https://www.ato.gov.au/rates/individual-income-tax-rates/';

UPDATE `sources`
SET `url` = 'https://www.ato.gov.au/individuals-and-families/investments-and-assets/capital-gains-tax/calculating-your-cgt/cgt-discount'
WHERE `url` = 'https://www.ato.gov.au/Individuals/Capital-gains-tax/CGT-discount/';

UPDATE `sources`
SET `url` = 'https://www.ato.gov.au/tax-rates-and-codes/key-superannuation-rates-and-thresholds/contributions-caps',
    `title` = 'Contributions caps'
WHERE `url` = 'https://www.ato.gov.au/Rates/key-superannuation-rates-and-thresholds/?page=2';

DELETE FROM `sources`
WHERE `url` IN (
  'https://www.ato.gov.au/rates/key-superannuation-rates-and-thresholds/?page=4',
  'https://www.housingaustralia.gov.au/node/5990'
);
