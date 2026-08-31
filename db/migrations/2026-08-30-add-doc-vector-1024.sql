-- Migration: Add 1024-dim document embedding column to laana.contents
-- Mirrors ES text_vector_1024 — all other existing vectors in this DB are
-- vector(384); this is the only 1024-dim column.
-- Idempotent: ADD COLUMN IF NOT EXISTS is safe to run repeatedly.

ALTER TABLE laana.contents
  ADD COLUMN IF NOT EXISTS embedding_1024 public.vector(1024);
