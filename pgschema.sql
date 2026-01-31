-- =========================================================
-- EXTENSIONS & SCHEMA
-- =========================================================

CREATE SCHEMA IF NOT EXISTS laana;

CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS vector;

-- Immutable unaccent wrapper
CREATE OR REPLACE FUNCTION laana.immutable_unaccent(text)
RETURNS text
LANGUAGE sql
IMMUTABLE
AS $$ SELECT unaccent($1); $$;

-- =========================================================
-- CORE HELPER FUNCTIONS
-- =========================================================

CREATE OR REPLACE FUNCTION laana.simplify_hawaiian(str text)
RETURNS text
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT replace(
           replace(
           replace(
           replace(
           replace(
           replace(
           replace(
           replace(
           replace(
           replace(
           replace(
           replace(str,
               'ō','o'),'ī','i'),'ē','e'),'ū','u'),'ā','a'),
               'Ō','O'),'Ī','I'),'Ē','E'),'Ū','U'),'Ā','A'),
               'ʻ',''),'‘','');
$$;

CREATE OR REPLACE FUNCTION laana.hawaiian_word_count(str text)
RETURNS int
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT CASE
        WHEN str IS NULL OR btrim(str) = '' THEN 0
        ELSE length(regexp_replace(btrim(str), '\s+', ' ', 'g'))
             - length(replace(regexp_replace(btrim(str), '\s+', ' ', 'g'), ' ', ''))
             + 1
    END;
$$;

CREATE OR REPLACE FUNCTION laana.hawaiian_syllable_count(str text)
RETURNS int
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT (
        SELECT count(*)
        FROM regexp_matches(
            laana.simplify_hawaiian(str),
            '[aeiouAEIOU]+',
            'g'
        ) AS m
    );
$$;

-- If you still want normalization trigger, define it explicitly here.
-- Stub example (adjust to your real logic):
CREATE OR REPLACE FUNCTION laana.normalize_hawaiian()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.hawaiiantext IS NOT NULL THEN
        NEW.hawaiiantext := btrim(NEW.hawaiiantext);
    END IF;
    RETURN NEW;
END;
$$;

-- =========================================================
-- 1. SOURCES
-- =========================================================

CREATE TABLE laana.sources (
    sourceid   bigint PRIMARY KEY,
    sourcename text,
    authors    text,
    link       text UNIQUE,
    created    timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    groupname  text,
    title      text,
    date       date
);

CREATE INDEX sources_authors_idx   ON laana.sources (authors);
CREATE INDEX sources_date_idx      ON laana.sources (date);
CREATE INDEX sources_sourcename_idx ON laana.sources (sourcename);

-- =========================================================
-- 2. CONTENTS
-- =========================================================

CREATE TABLE laana.contents (
    sourceid   bigint PRIMARY KEY
               REFERENCES laana.sources(sourceid) ON DELETE CASCADE,
    html       text,
    text       text,
    created    timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    embedding  public.vector(384),
    text_tsv   tsvector GENERATED ALWAYS AS (
                   to_tsvector('simple'::regconfig, text)
               ) STORED
);

-- FK index (even though PK, explicit for clarity)
CREATE INDEX contents_sourceid_idx ON laana.contents (sourceid);

-- =========================================================
-- 3. SENTENCES
-- =========================================================

CREATE TABLE laana.sentences (
    sentenceid   bigint PRIMARY KEY,
    sourceid     bigint NOT NULL
                 REFERENCES laana.sources(sourceid) ON DELETE CASCADE,
    hawaiiantext text,
    englishtext  text,
    created      timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    simplified   text GENERATED ALWAYS AS (
                     laana.simplify_hawaiian(hawaiiantext)
                 ) STORED,
    wordcount    int  GENERATED ALWAYS AS (
                     laana.hawaiian_word_count(hawaiiantext)
                 ) STORED,
    embedding    public.vector(384),
    hawaiian_tsv tsvector GENERATED ALWAYS AS (
                     to_tsvector('simple'::regconfig, hawaiiantext)
                 ) STORED,
    hawaiian_unaccent_tsv tsvector GENERATED ALWAYS AS (
                     to_tsvector('simple'::regconfig,
                                 laana.immutable_unaccent(hawaiiantext))
                 ) STORED
);

-- FK index
CREATE INDEX idx_sentences_source ON laana.sentences (sourceid);

-- Text search indexes (no duplicates)
CREATE INDEX sentences_hawaiian_tsv_gin
    ON laana.sentences USING gin (hawaiian_tsv);

CREATE INDEX sentences_hawaiian_unaccent_tsv_gin
    ON laana.sentences USING gin (hawaiian_unaccent_tsv);

-- Optional: normalization trigger
CREATE TRIGGER trg_normalize_sentences
BEFORE INSERT OR UPDATE ON laana.sentences
FOR EACH ROW EXECUTE FUNCTION laana.normalize_hawaiian();

-- =========================================================
-- 4. DOCUMENTS
-- =========================================================

CREATE TABLE laana.documents (
    doc_id     bigint PRIMARY KEY,
    groupname  varchar(50),
    sourcename varchar(255),
    authors    text,
    date       date,
    link       text,
    title      varchar(255),
    text       text,
    text_vector public.vector(384)
);

-- Add indexes as needed later (e.g., on date, groupname)

-- =========================================================
-- 5. METRICS
-- =========================================================

CREATE TABLE laana.sentence_metrics (
    sentenceid        bigint PRIMARY KEY
                      REFERENCES laana.sentences(sentenceid) ON DELETE CASCADE,
    hawaiian_word_ratio real,
    word_count        integer,
    length            integer,
    entity_count      integer,
    frequency         integer,
    updated_at        timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX sentence_metrics_sentenceid_idx
    ON laana.sentence_metrics (sentenceid);

CREATE TABLE laana.document_metrics (
    sourceid          bigint PRIMARY KEY
                      REFERENCES laana.contents(sourceid) ON DELETE CASCADE,
    hawaiian_word_ratio real,
    word_count        integer,
    length            integer,
    entity_count      integer,
    updated_at        timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX document_metrics_sourceid_idx
    ON laana.document_metrics (sourceid);

-- =========================================================
-- 6. SENTENCE PATTERNS
-- =========================================================

CREATE TABLE laana.sentence_patterns (
    patternid    bigserial PRIMARY KEY,
    sentenceid   bigint NOT NULL
                 REFERENCES laana.sentences(sentenceid) ON DELETE CASCADE,
    pattern_type text NOT NULL,
    signature    text,
    confidence   double precision DEFAULT 1.0,
    created_at   timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_sentence_pattern UNIQUE (sentenceid, pattern_type)
);

CREATE INDEX sentence_patterns_sentenceid_idx
    ON laana.sentence_patterns (sentenceid);

CREATE INDEX sentence_patterns_type_idx
    ON laana.sentence_patterns (pattern_type);

-- =========================================================
-- 7. LOGGING / SEARCH STATS
-- =========================================================

CREATE TABLE laana.processing_log (
    log_id         bigint PRIMARY KEY,
    operation_type text,
    source_id      bigint,
    groupname      text,
    parser_key     text,
    status         text,
    sentences_count bigint,
    started_at     timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    completed_at   timestamp without time zone,
    error_message  text,
    metadata       text
);

CREATE INDEX processing_log_source_id_idx
    ON laana.processing_log (source_id);

CREATE TABLE laana.searchstats (
    searchterm text,
    created    timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    results    bigint,
    pattern    text,
    elapsed    double precision,
    sort       text
);

-- =========================================================
-- 8. (OPTIONAL) STATS WITHOUT PER-ROW TRIGGERS
-- =========================================================
-- Instead of laana.stats + per-row triggers, prefer a view or
-- query against pg_class when you need counts.

-- Example view:
CREATE OR REPLACE VIEW laana.table_row_counts AS
SELECT 'sources'::text   AS name, count(*)::bigint AS value FROM laana.sources
UNION ALL
SELECT 'contents',       count(*) FROM laana.contents
UNION ALL
SELECT 'sentences',      count(*) FROM laana.sentences
UNION ALL
SELECT 'sentence_metrics', count(*) FROM laana.sentence_metrics
UNION ALL
SELECT 'sentence_patterns', count(*) FROM laana.sentence_patterns
UNION ALL
SELECT 'documents',      count(*) FROM laana.documents;

-- =========================================================
-- 9. MATERIALIZED VIEWS
-- =========================================================

CREATE MATERIALIZED VIEW laana.grammar_pattern_counts AS
SELECT pattern_type,
       count(*) AS total_count
FROM laana.sentence_patterns
GROUP BY pattern_type;

CREATE UNIQUE INDEX idx_pattern_type_counts
    ON laana.grammar_pattern_counts (pattern_type);

-- =========================================================
-- 10. VECTOR INDEXES (CREATE AFTER DATA LOAD)
-- =========================================================

-- Run these AFTER bulk import for best performance:

-- Contents embedding
CREATE INDEX contents_embedding_ivfflat
    ON laana.contents USING ivfflat (embedding public.vector_cosine_ops);

-- Sentences embedding
CREATE INDEX sentences_embedding_ivfflat
    ON laana.sentences USING ivfflat (embedding public.vector_cosine_ops)
    WITH (lists = 1000);

-- Documents embedding
CREATE INDEX documents_text_vec_ivfflat
    ON laana.documents USING ivfflat (text_vector public.vector_cosine_ops);
