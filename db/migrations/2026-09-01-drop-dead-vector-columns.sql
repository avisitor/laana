-- Migration: Drop dead vector storage in Postgres
--   1. laana.contents.embedding (vector 384)  -- empty, never read or written
--      by any code; superseded by contents.embedding_1024 (see
--      2026-08-30-add-doc-vector-1024.sql)
--   2. laana.documents table                  -- 0 rows, no code reads or
--      writes it; includes its text_vector column and
--      documents_text_vec_ivfflat index
-- The laana.table_row_counts view is recreated without its documents branch
-- first so the DROP TABLE has no dependents.
-- Idempotent: IF EXISTS guards + CREATE OR REPLACE VIEW are safe to re-run.

BEGIN;

CREATE OR REPLACE VIEW laana.table_row_counts AS
SELECT 'sources'::text   AS name, count(*)::bigint AS value FROM laana.sources
UNION ALL
SELECT 'contents',       count(*) FROM laana.contents
UNION ALL
SELECT 'sentences',      count(*) FROM laana.sentences
UNION ALL
SELECT 'sentence_metrics', count(*) FROM laana.sentence_metrics
UNION ALL
SELECT 'sentence_patterns', count(*) FROM laana.sentence_patterns;

DROP INDEX IF EXISTS laana.contents_embedding_ivfflat;
ALTER TABLE laana.contents DROP COLUMN IF EXISTS embedding;

DROP TABLE IF EXISTS laana.documents;

COMMIT;
