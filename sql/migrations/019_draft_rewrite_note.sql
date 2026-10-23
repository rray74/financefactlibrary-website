-- What the automatic rewrite did to a draft (includes/ai-drafts.php,
-- reviseDraft()): for example "Rewrite round 1: 13 problems found, 2 left."
-- Shown in the editor's draft panel, so a draft's history is visible.
-- Empty when nothing needed fixing or the rewrite wasn't attempted.
ALTER TABLE subject_drafts ADD COLUMN rewrite_note TEXT NULL;
