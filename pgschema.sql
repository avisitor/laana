-- 1. SOURCES TABLE
CREATE TABLE laana.sources (
    sourceid bigint PRIMARY KEY,
    sourcename text,
    authors text,
    link text UNIQUE,
    created timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    groupname text,
    title text,
    date date
);

-- 2. CONTENTS TABLE
CREATE TABLE laana.contents (
    sourceid bigint PRIMARY KEY,
    html text,
    text text,
    created timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    embedding public.vector(384),
    text_tsv tsvector GENERATED ALWAYS AS (to_tsvector('simple'::regconfig, text)) STORED
);

-- 3. SENTENCES TABLE
CREATE TABLE laana.sentences (
    sentenceid bigint PRIMARY KEY,
    sourceid bigint REFERENCES laana.sources(sourceid) ON DELETE CASCADE,
    hawaiiantext text,
    englishtext text,
    created timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    simplified text GENERATED ALWAYS AS (simplify_hawaiian(hawaiiantext)) STORED,
    wordcount int GENERATED ALWAYS AS (hawaiian_word_count(hawaiiantext)) STORED,
    embedding public.vector(384)
);

-- 4. DOCUMENTS TABLE
CREATE TABLE laana.documents (
    doc_id bigint PRIMARY KEY,
    groupname character varying(50),
    sourcename character varying(255),
    authors text,
    date date,
    link text,
    title character varying(255),
    text text,
    text_vector public.vector(384)
);

-- 5. METRICS TABLES
CREATE TABLE laana.sentence_metrics (
    sentenceid integer PRIMARY KEY REFERENCES laana.sentences(sentenceid) ON DELETE CASCADE,
    hawaiian_word_ratio real,
    word_count integer,
    length integer,
    entity_count integer,
    frequency integer,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE laana.document_metrics (
    sourceid bigint PRIMARY KEY REFERENCES laana.contents(sourceid) ON DELETE CASCADE,
    hawaiian_word_ratio real,
    word_count integer,
    length integer,
    entity_count integer,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

-- 6. PATTERNS TABLE
CREATE TABLE laana.sentence_patterns (
    patternid SERIAL PRIMARY KEY,
    sentenceid bigint REFERENCES laana.sentences(sentenceid) ON DELETE CASCADE,
    pattern_type text NOT NULL,
    signature text,
    confidence double precision DEFAULT 1.0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_sentence_pattern UNIQUE (sentenceid, pattern_type)
);

-- 7. LOGGING AND STATS
CREATE TABLE laana.processing_log (
    log_id bigint PRIMARY KEY,
    operation_type text,
    source_id double precision,
    groupname text,
    parser_key text,
    status text,
    sentences_count bigint,
    started_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    completed_at timestamp without time zone,
    error_message text,
    metadata text
);

CREATE TABLE laana.searchstats (
    searchterm text,
    created timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    results bigint,
    pattern text,
    elapsed double precision,
    sort text
);

CREATE TABLE laana.stats (
    name text PRIMARY KEY,
    value bigint NOT NULL DEFAULT 0
);

-- 8. TRIGGERS FOR STATS AND NORMALIZATION
CREATE OR REPLACE FUNCTION laana.update_stats() RETURNS TRIGGER AS $$
BEGIN
    IF (TG_OP = 'INSERT') THEN
        UPDATE laana.stats SET value = value + 1 WHERE name = TG_TABLE_NAME;
        RETURN NEW;
    ELSIF (TG_OP = 'DELETE') THEN
        UPDATE laana.stats SET value = value - 1 WHERE name = TG_TABLE_NAME;
        RETURN OLD;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

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

CREATE OR REPLACE FUNCTION hawaiian_word_count(str text)
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

CREATE OR REPLACE FUNCTION hawaiian_syllable_count(str text)
RETURNS int
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT (
        SELECT count(*)
        FROM regexp_matches(
            simplify_hawaiian(str),
            '[aeiouAEIOU]+',
            'g'
        ) AS m
    );
$$;

CREATE TRIGGER trg_normalize_sentences BEFORE INSERT OR UPDATE ON laana.sentences
FOR EACH ROW EXECUTE FUNCTION laana.normalize_hawaiian();

CREATE TRIGGER trg_stats_sentences AFTER INSERT OR DELETE ON laana.sentences FOR EACH ROW EXECUTE FUNCTION laana.update_stats();
CREATE TRIGGER trg_stats_sources AFTER INSERT OR DELETE ON laana.sources FOR EACH ROW EXECUTE FUNCTION laana.update_stats();
CREATE TRIGGER trg_stats_sentence_patterns AFTER INSERT OR DELETE ON laana.sentence_patterns FOR EACH ROW EXECUTE FUNCTION laana.update_stats();
CREATE TRIGGER trg_stats_contents AFTER INSERT OR DELETE ON laana.contents FOR EACH ROW EXECUTE FUNCTION laana.update_stats();

-- 8. MATERIALIZED VIEWS
CREATE MATERIALIZED VIEW laana.grammar_pattern_counts AS
 SELECT pattern_type,
    count(*) AS total_count
   FROM laana.sentence_patterns
  GROUP BY pattern_type;

CREATE UNIQUE INDEX idx_pattern_type_counts ON laana.grammar_pattern_counts (pattern_type);

-- 9. INDEXES AND FUNCTIONS
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE OR REPLACE FUNCTION laana.immutable_unaccent(text) RETURNS text
    LANGUAGE sql IMMUTABLE
    AS $$ SELECT unaccent($1); $$;

CREATE INDEX idx_sentences_source ON laana.sentences (sourceid);
CREATE INDEX idx_sentences_tsvector ON laana.sentences USING gin (to_tsvector('simple'::regconfig, hawaiiantext));
CREATE INDEX sentences_hawaiiantext_tsv_gin ON laana.sentences USING gin (to_tsvector('simple'::regconfig, hawaiiantext));
CREATE INDEX sentences_simplified_tsv_gin ON laana.sentences USING gin (to_tsvector('simple'::regconfig, simplified));
CREATE INDEX sentences_unaccent_vec_gin ON laana.sentences USING gin (to_tsvector('simple'::regconfig, laana.immutable_unaccent(hawaiiantext)));

CREATE INDEX contents_embedding_ivfflat ON laana.contents USING ivfflat (embedding public.vector_cosine_ops);
CREATE INDEX sentences_embedding_ivfflat ON laana.sentences USING ivfflat (embedding public.vector_cosine_ops) WITH (lists='1000');
CREATE INDEX documents_text_vec_ivfflat ON laana.documents USING ivfflat (text_vector public.vector_cosine_ops);

CREATE INDEX sources_authors_idx ON laana.sources (authors);
CREATE INDEX sources_date_idx ON laana.sources (date);
CREATE INDEX sources_sourceid_idx ON laana.sources (sourceid);
CREATE INDEX sources_sourcename_idx ON laana.sources (sourcename);
