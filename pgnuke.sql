-- 1. Drop dependent materialized views first (if you want to be explicit)
DROP MATERIALIZED VIEW IF EXISTS laana.grammar_pattern_counts;

-- 2. Drop the whole schema and everything in it
DROP SCHEMA IF EXISTS laana CASCADE;

-- 3. Drop helper functions that live outside laana (if they do)
DROP FUNCTION IF EXISTS simplify_hawaiian(text);
DROP FUNCTION IF EXISTS hawaiian_word_count(text);
DROP FUNCTION IF EXISTS hawaiian_syllable_count(text);

-- If you created immutable_unaccent in public instead of laana:
DROP FUNCTION IF EXISTS immutable_unaccent(text);

-- 4. (Optional) drop extensions if you want a truly bare restart
-- Only do this if nothing else in the DB uses them.
DROP EXTENSION IF EXISTS unaccent;
DROP EXTENSION IF EXISTS vector;
