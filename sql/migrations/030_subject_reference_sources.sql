-- 030_subject_reference_sources.sql
--
-- Reference sources for a page: official pages that support what the page's TEXT says
-- but that no figure card cites. Added 21 Nov 2026.
--
-- WHY. The AI check, Propose fix and the drafting loop read only the source pages that the
-- page's figure cards cite. A claim in the text that comes from another official page (for
-- example GOV.UK saying the person you care for "will usually stop getting" a premium) cannot be
-- seen by them, so correct text gets flagged as unsupported, costing review time and AI spend.
-- A reference source is read in addition to the card sources, and also counts for the
-- close-copy and hedge-word checks and for noticing that a source page has changed.
--
-- A reference source is NOT shown to readers and is not a figure, so adding one changes no
-- page and no figure. Rows are added with scripts/import-reference-sources.php.
--
-- Only allowlisted domains are accepted by that script, as for figure sources.
--
-- No semicolons appear inside the comments in this file, so it is safe for the runner that
-- splits a migration into statements on semicolons.

CREATE TABLE subject_reference_sources (
    subject_id INT NOT NULL,
    source_id INT NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (subject_id, source_id),
    KEY idx_reference_source (source_id),
    CONSTRAINT fk_reference_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
    CONSTRAINT fk_reference_source FOREIGN KEY (source_id) REFERENCES sources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
