#!/usr/bin/env python3
import os
import sys
import time
import json
from typing import List, Tuple, Optional, Set
# 1. CRITICAL CHANGE: Import ProcessPoolExecutor
from concurrent.futures import ProcessPoolExecutor, as_completed
# 2. CRITICAL CHANGE: Use process-safe multiprocessing tools
from multiprocessing import Lock, Manager 
import gc

from dotenv import load_dotenv

try:
    import psycopg2
    from psycopg2.extras import execute_batch
except Exception as e:
    print("Missing dependency psycopg2-binary. Install requirements in noiiolelo/scripts/requirements.txt.", file=sys.stderr)
    raise

try:
    import torch
    from sentence_transformers import SentenceTransformer
except Exception as e:
    print("Missing sentence-transformers/torch. Ensure the Python env has them installed.", file=sys.stderr)
    raise

# 3. GLOBAL STATE CONTAINER for Process-Local variables
# This dictionary holds state unique to each process (the model, spacy, etc.)
_process_local_state = {
    'model': None,
    'spacy_nlp': None,
    'hawaiian_word_set': None,
}

# Optional: spaCy for entity_count backfill
def get_spacy_nlp():
    global _process_local_state
    if _process_local_state['spacy_nlp'] is not None:
        return _process_local_state['spacy_nlp']
    try:
        import spacy
        # Load inside the process
        _process_local_state['spacy_nlp'] = spacy.load("en_core_web_sm")
        return _process_local_state['spacy_nlp']
    except Exception as e:
        # print(f"spaCy not available or en_core_web_sm not installed: {e}", file=sys.stderr)
        return None

# --- Worker Initialization ---
# 4. CRITICAL FUNCTION: Called once when a worker process starts
def worker_init(model_name: str, device: str, hawaiian_word_set: Set[str]):
    """Initializes the model and shared state in each worker process."""
    global _process_local_state
    
    # 4a. Load PyTorch/SentenceTransformer Model
    if not _process_local_state['model'] and model_name:
        if device == 'auto':
            device = 'cuda' if torch.cuda.is_available() else 'cpu'
        print(f"[Worker {os.getpid()}] Loading model '{model_name}' on device '{device}'...")
        _process_local_state['model'] = SentenceTransformer(model_name, device=device)
    
    # 4b. Load other shared, immutable data
    _process_local_state['hawaiian_word_set'] = hawaiian_word_set
    
    # Pre-load spaCy if available
    get_spacy_nlp()


def getenv(name: str, default: str = "") -> str:
    v = os.getenv(name)
    return v if v is not None and v != "" else default


def get_db_conn():
    host = getenv('PG_HOST', getenv('PGHOST', 'localhost'))
    port = getenv('PG_PORT', getenv('PGPORT', '5432'))
    db = getenv('PG_DATABASE', getenv('PG_DB', getenv('PGDATABASE', '')))
    user = getenv('PG_USER', getenv('PGUSER', ''))
    password = getenv('PG_PASSWORD', getenv('PGPASSWORD', ''))
    dsn = f"host={host} port={port} dbname={db} user={user} password={password}"
    conn = psycopg2.connect(dsn)
    conn.autocommit = False
    with conn.cursor() as cur:
        cur.execute("SET client_encoding TO 'UTF8'")
        cur.execute("SET search_path TO laana, public")
    return conn


def detect_columns(conn, table: str) -> Tuple[str, str]:
    if table == 'sentences':
        # Prefer lowercase names; fall back if needed
        text_candidates = ['hawaiiantext', 'hawaiianText', 'text']
        id_candidates = ['sentenceid', 'id']
    else:
        text_candidates = ['text', 'body']
        id_candidates = ['docid', 'id']

    text_col = None
    id_col = None
    with conn.cursor() as cur:
        for cand in text_candidates:
            try:
                cur.execute(f"SELECT COUNT(*) FROM laana.{table} WHERE octet_length({cand}) > 0")
                cur.fetchone()
                text_col = cand
                break
            except Exception:
                continue
        for cand in id_candidates:
            try:
                cur.execute(f"SELECT COUNT({cand}) FROM laana.{table}")
                cur.fetchone()
                id_col = cand
                break
            except Exception:
                continue
    if not text_col or not id_col:
        raise RuntimeError(f"Unable to detect columns for table {table} (text={text_col}, id={id_col})")
    return id_col, text_col


def count_remaining(conn, table: str, id_col: str, text_col: str) -> int:
    with conn.cursor() as cur:
        cur.execute(f"SELECT COUNT(*) FROM laana.{table} WHERE embedding IS NULL AND octet_length({text_col}) > 0")
        return int(cur.fetchone()[0])


def fetch_batch(conn, table: str, id_col: str, text_col: str, limit: int) -> List[Tuple[int, str]]:
    with conn.cursor() as cur:
        sql = f"""
        SELECT {id_col} AS id, {text_col} AS text
        FROM laana.{table}
        WHERE embedding IS NULL AND octet_length({text_col}) > 0
        ORDER BY {id_col} ASC
        LIMIT %s
        """
        cur.execute(sql, (limit,))
        rows = cur.fetchall()
    return [(r[0], r[1]) for r in rows]


def to_vector_literal(vec: List[float]) -> str:
    return '[' + ','.join(f"{float(x):.6f}" for x in vec) + ']'


def update_embeddings(conn, table: str, id_col: str, items: List[Tuple[int, str]], vectors: List[List[float]]):
    if not items:
        return
    sql = f"UPDATE laana.{table} SET embedding = %s WHERE {id_col} = %s"
    params = [(to_vector_literal(vectors[i]), items[i][0]) for i in range(len(items))]
    with conn.cursor() as cur:
        execute_batch(cur, sql, params, page_size=max(64, min(1000, len(params))))
    conn.commit()


def load_model(model_name: str, device: str = 'auto') -> SentenceTransformer:
    # This is ONLY used for single-threaded mode (workers=1)
    if device == 'auto':
        device = 'cuda' if torch.cuda.is_available() else 'cpu'
    print(f"Loading model '{model_name}' on device '{device}'...")
    model = SentenceTransformer(model_name, device=device)
    return model


def maybe_prefix_texts(texts: List[str], prefix: Optional[str]) -> List[str]:
    if prefix:
        p = prefix.strip()
        if p and not p.endswith(':'):
            p = p + ':'
        p = p + ' '
        return [p + (t if t is not None else '') for t in texts]
    return texts


# 5. MODIFIED: calc_hawaiian_word_ratio uses process-local set
def calc_hawaiian_word_ratio(text: str) -> float:
    hawaiian_word_set = _process_local_state['hawaiian_word_set']
    if hawaiian_word_set is None or not text or not text.strip():
        return 0.0

    # Patterns similar to PHP:
    diacritic_chars = set("āĀēĒīĪōŌūŪʻ’'")
    
    import re
    word_pattern = re.compile(r"\b\w+\b", re.UNICODE)
    words = word_pattern.findall(text)
    word_count = len(words)
    if word_count == 0:
        return 0.0

    # Normalization table for macrons
    macron_map = str.maketrans({
         'ā': 'a', 'ē': 'e', 'ī': 'i', 'ō': 'o', 'ū': 'u',
         'Ā': 'A', 'Ē': 'E', 'Ī': 'I', 'Ō': 'O', 'Ū': 'U'
     })

    def normalize_word(w: str) -> str:
        # Remove ASCII apostrophes and okinas, convert macrons, lowercase
        w = w.replace("'", "")
        w = w.replace("ʻ", "")
        w = w.translate(macron_map)
        return w.lower().strip()

    hawaiian_word_count = 0
    # EXACT match to PHP diacritic indicator: macron vowels and ASCII apostrophe only
    diacritic_chars = set("āĀēĒīĪōŌūŪ'‘")
    for w in words:
        if any(ch in diacritic_chars for ch in w):
            hawaiian_word_count += 1
        else:
            nw = normalize_word(w)
            if nw in hawaiian_word_set:
                hawaiian_word_count += 1

    return hawaiian_word_count / word_count

# 6. MODIFIED: calc_entity_count uses process-local spacy
def calc_entity_count(text: str) -> int:
    nlp = get_spacy_nlp()
    if nlp is None or not text:
        return 0
    try:
        doc = nlp(text)
        return len(doc.ents)
    except Exception:
        return 0

# NOTE: calc_frequency is kept, but its use is disabled in parallel mode (workers > 1)
def calc_frequency(conn, table: str, text_col: str, txt: str) -> int:
    try:
        with conn.cursor() as cur:
            cur.execute(f"SELECT COUNT(*) FROM laana.{table} WHERE {text_col} = %s", (txt,))
            return int(cur.fetchone()[0])
    except Exception:
        return 0

# 7. MODIFIED: The function signature and access to shared state updated
def process_batch_worker(batch_items: List[Tuple[int, str]], id_col: str, text_col: str, table: str, prefix: Optional[str], metrics_only: bool, processed_lock: Lock, processed_counter: 'Manager.Value', initial_remaining: Optional[int], is_parallel: bool):
    """Worker function to process a batch of items - now process-safe"""
    
    if not batch_items:
        return 0
    
    batch_start_time = time.time()
    
    # Retrieve model from process-local state
    model = _process_local_state['model']
    
    # Each worker gets its own DB connection
    worker_conn = get_db_conn()
    
    try:
        ids = [b[0] for b in batch_items]
        texts = [b[1] for b in batch_items]

        # Filter empties defensively
        filtered = [(i, t) for (i, t) in zip(ids, texts) if isinstance(t, str) and t.strip()]
        if not filtered:
            worker_conn.close()
            return 0
        ids, texts = zip(*filtered)
        texts = list(texts)
        batch_count = len(ids)

        vectors_list = None
        if not metrics_only:
            enc_texts = maybe_prefix_texts(texts, prefix)
            
            # --- PYTORCH/SENTENCE TRANSFORMERS EXECUTION ---
            vectors_tensor = model.encode(enc_texts, normalize_embeddings=True, batch_size=min(512, max(8, len(enc_texts))))
            
            # CRITICAL CLEANUP: Delete input texts and convert tensor
            del enc_texts
            
            # 3. CONVERT to list for DB, then DELETE the tensor
            vectors_list = vectors_tensor.tolist()
            del vectors_tensor  # Delete the large PyTorch tensor
            
            # 4. Force Python GC
            gc.collect()
            if torch.cuda.is_available():
                 torch.cuda.empty_cache() # Clear CUDA cache if applicable
            # --- END PYTORCH ---

        # Update DB
        if not metrics_only and vectors_list is not None:
            update_embeddings(worker_conn, table, id_col, list(zip(ids, texts)), vectors_list)
        
        # Prefer writing to sentence_metrics table when present
        metrics_table = None
        try:
             with worker_conn.cursor() as cur:
                 cur.execute("SELECT to_regclass('laana.sentence_metrics')")
                 r = cur.fetchone()
                 if r and r[0]:
                     metrics_table = 'sentence_metrics'
        except Exception:
             metrics_table = None

        if metrics_table:
             # Compute metrics and upsert into sentence_metrics
             upsert_sql = """
             INSERT INTO laana.sentence_metrics (sentenceid, hawaiian_word_ratio, word_count, length, entity_count, frequency, updated_at)
             VALUES (%s, %s, %s, %s, %s, %s, CURRENT_TIMESTAMP)
             ON CONFLICT (sentenceid) DO UPDATE SET
               hawaiian_word_ratio = EXCLUDED.hawaiian_word_ratio,
               word_count = EXCLUDED.word_count,
               length = EXCLUDED.length,
               entity_count = EXCLUDED.entity_count,
               frequency = EXCLUDED.frequency,
               updated_at = CURRENT_TIMESTAMP
             """
             params = []
             batch_ratios = []
             batch_word_counts = []
             batch_lengths = []
             batch_entity_counts = []
             batch_frequencies = []
             
             # Disable expensive calc_frequency in parallel mode
             calc_freq = calc_frequency if not is_parallel else (lambda *a: 0)

             for i, t in enumerate(texts):
                 ratio = calc_hawaiian_word_ratio(t) 
                 wc = len(t.split())
                 ln = len(t)
                 ec = calc_entity_count(t)
                 # Only call calc_frequency if not parallel
                 fq = calc_freq(worker_conn, table, text_col, t)
                 
                 params.append((ids[i], ratio, wc, ln, ec, fq))
                 batch_ratios.append(ratio)
                 batch_word_counts.append(wc)
                 batch_lengths.append(ln)
                 batch_entity_counts.append(ec)
                 batch_frequencies.append(fq)
             with worker_conn.cursor() as cur:
                 execute_batch(cur, upsert_sql, params, page_size=max(64, min(1000, len(params))))
             worker_conn.commit()
             
             def _avg(lst):
                 return (sum(lst) / len(lst)) if lst else 0.0
             avg_ratio = _avg(batch_ratios)
             avg_wc = _avg(batch_word_counts)
             avg_len = _avg(batch_lengths)
             avg_ec = _avg(batch_entity_counts)
             avg_fq = _avg(batch_frequencies)
             
             batch_elapsed = time.time() - batch_start_time
             
             # 8. CRITICAL: Update shared counter
             with processed_lock:
                 processed_counter.value += batch_count
                 current_processed = processed_counter.value
             
             metrics_summary = f" | avg_ratio={avg_ratio:.3f} avg_wc={avg_wc:.1f} avg_len={avg_len:.1f} avg_ec={avg_ec:.2f}"
             if not is_parallel and avg_fq > 0:
                  metrics_summary += f" avg_fq={avg_fq:.2f}"
             metrics_summary += f" | elapsed={batch_elapsed:.2f}s"
             
             # Determine the total value for the progress bar
             total_for_progress = initial_remaining if metrics_only else None
             
             if total_for_progress is not None and is_parallel:
                 print(f"Processed batch: {batch_count} Progress: {current_processed} / {total_for_progress} / {initial_remaining}{metrics_summary}")
             elif not is_parallel and total_for_progress is not None:
                  print(f"Processed batch: {batch_count} Progress: {current_processed} / {total_for_progress} / {initial_remaining}{metrics_summary}")
             else:
                 # Fallback print for embedding mode
                 print(f"Processed batch: {batch_count} Total: {current_processed} / {initial_remaining}{metrics_summary}")
             
             worker_conn.close()
             return batch_count
        else:
            print(f"DB update failed: table sentence_metrics does not exist", file=sys.stderr)
            worker_conn.close()
            return 0
            
    except Exception as e:
        print(f"Worker batch processing failed: {e}", file=sys.stderr)
        worker_conn.rollback()
        worker_conn.close()
        return 0


def main():
    # ... (argparse setup) ...
    import argparse
    parser = argparse.ArgumentParser(description='Backfill embeddings and/or metrics for sentences/documents')
    parser.add_argument('table', nargs='?', default='sentences', help='Target table: sentences or documents')
    parser.add_argument('batch_size', nargs='?', type=int, default=int(getenv('EMBED_BATCH_SIZE', '100') or 100), help='Batch size')
    parser.add_argument('--sleep', dest='sleep_secs', type=float, default=float(getenv('EMBED_SLEEP', '0') or 0), help='Sleep seconds between batches')
    parser.add_argument('--metrics-only', dest='metrics_only', action='store_true', help='Only backfill metrics for rows that already have embeddings')
    parser.add_argument('--workers', dest='workers', type=int, default=int(getenv('EMBED_WORKERS', '1') or 1), help='Number of parallel worker processes')
    args, unknown = parser.parse_known_args()

    # Load .env
    env_path = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '.env'))
    if os.path.exists(env_path):
        load_dotenv(env_path)
    else:
        load_dotenv()
        
    table = args.table
    batch_size = max(1, int(args.batch_size))
    sleep_secs = float(args.sleep_secs)
    workers = max(1, int(args.workers))
    model_name = getenv('EMBED_MODEL', 'intfloat/multilingual-e5-small')
    device = getenv('EMBED_DEVICE', 'auto')
    prefix = getenv('EMBED_PREFIX', 'passage: ')
    metrics_only = bool(args.metrics_only)

    print(f"Configuration: workers={workers}, batch_size={batch_size}, table={table}")

    conn = get_db_conn()
    id_col, text_col = detect_columns(conn, table)
    print(f"Using table={table}, id_col={id_col}, text_col={text_col}, batch_size={batch_size}")

    initial_remaining = None
    if metrics_only:
        # Compute initial total to process for progress reporting
        try:
            with conn.cursor() as cur:
                cur.execute(
                    f"""
                    SELECT COUNT(*)
                    FROM laana.{table} s
                    LEFT JOIN laana.sentence_metrics m ON m.sentenceid = s.{id_col}
                    WHERE s.embedding IS NOT NULL AND m.sentenceid IS NULL AND octet_length(s.{text_col}) > 0
                    """
                )
                initial_remaining = int(cur.fetchone()[0])
                print(f"Initial metrics backfill candidates: {initial_remaining}")
        except Exception as e:
            print(f"Failed to count initial metrics-only candidates: {e}", file=sys.stderr)
            initial_remaining = None
    else:
        initial_remaining = count_remaining(conn, table, id_col, text_col)
        print(f"Remaining to embed in {table}: {initial_remaining}")


    # Show a sample candidate
    # ... (sample query logic remains the same) ...
    try:
         with conn.cursor() as cur:
             if metrics_only:
                 cur.execute(
                     f"SELECT {id_col}, octet_length({text_col}) AS len FROM laana.{table} "
                     f"WHERE embedding IS NOT NULL AND octet_length({text_col}) > 0 ORDER BY {id_col} ASC LIMIT 1"
                 )
             else:
                 cur.execute(
                     f"SELECT {id_col}, octet_length({text_col}) AS len FROM laana.{table} "
                     f"WHERE embedding IS NULL AND octet_length({text_col}) > 0 ORDER BY {id_col} ASC LIMIT 1"
                 )
             r = cur.fetchone()
             if r:
                 print(f"Sample candidate id={r[0]}, len={r[1]}")
    except Exception as e:
         print(f"Sample query failed: {e}", file=sys.stderr)


    # Load Hawaiian word set (in main process)
    # ... (Hawaiian word list loading logic remains the same) ...
    script_dir = os.path.dirname(__file__)
    candidates = [
        os.path.abspath(os.path.join(script_dir, '..', '..', 'noiiolelo', 'hawaiian_words.txt')),
        os.path.abspath(os.path.join(script_dir, '..', 'hawaiian_words.txt')),
        os.path.abspath(os.path.join(script_dir, '..', '..', 'elasticsearch', 'hawaiian_words.txt')),
        os.path.abspath(os.path.join(script_dir, '..', 'elasticsearch', 'hawaiian_words.txt')),
    ]
    hawaiian_words_file = None
    for p in candidates:
        if os.path.exists(p):
            hawaiian_words_file = p
            break
    if hawaiian_words_file is None:
        print("Hawaiian words file not found in candidates:", file=sys.stderr)
        for p in candidates:
            print(f" - {p}", file=sys.stderr)
    hawaiian_word_set: Optional[Set[str]] = None
    if hawaiian_words_file and os.path.exists(hawaiian_words_file):
        try:
            with open(hawaiian_words_file, 'r', encoding='utf-8') as f:
                hawaiian_word_set = set(w.strip() for w in f if w.strip())
            print(f"Loaded {len(hawaiian_word_set)} Hawaiian words for ratio calculation")
        except Exception as e:
            print(f"Could not load Hawaiian words list: {e}", file=sys.stderr)
            hawaiian_word_set = None
    else:
        hawaiian_word_set = None

    if hawaiian_word_set is None or len(hawaiian_word_set) == 0:
         print("Fatal: Hawaiian word dictionary missing or empty. Aborting metrics backfill to avoid garbage ratios.", file=sys.stderr)
         sys.exit(1)


    # 9. CRITICAL: Initialize shared state manager for process communication
    with Manager() as manager:
        processed_counter = manager.Value('i', 0)
        processed_lock = manager.Lock()
        
        # Only load model in main process if workers=1
        model = None
        if workers == 1 and not metrics_only:
            model = load_model(model_name, device=device)
            # 10. Manual initialization for single-threaded mode (workers=1)
            _process_local_state['model'] = model
            _process_local_state['hawaiian_word_set'] = hawaiian_word_set
            _process_local_state['spacy_nlp'] = get_spacy_nlp() # Pre-load spacy
        elif workers > 1 and not metrics_only:
             pass # Model loading is deferred to worker_init

        
        if workers > 1:
            print(f"Using parallel processing with {workers} **processes**")
            # 11. CRITICAL: Use ProcessPoolExecutor with initializer
            with ProcessPoolExecutor(max_workers=workers, initializer=worker_init, initargs=(model_name if not metrics_only else None, device, hawaiian_word_set)) as executor:
                while True:
                    # Fetch ONE batch at a time in the main process to avoid processing duplicates
                    if metrics_only:
                        # Complex metrics-only fetching logic (remains the same as before)
                        metrics_table_exists = False
                        try:
                            with conn.cursor() as cur:
                                cur.execute("SELECT to_regclass('laana.sentence_metrics')")
                                r = cur.fetchone()
                                metrics_table_exists = bool(r and r[0])
                        except Exception:
                            metrics_table_exists = False
                        
                        if metrics_table_exists:
                            with conn.cursor() as cur:
                                sql = f"""
                                SELECT s.{id_col} AS id, s.{text_col} AS text
                                FROM laana.{table} s
                                LEFT JOIN laana.sentence_metrics m ON m.sentenceid = s.{id_col}
                                WHERE s.embedding IS NOT NULL AND m.sentenceid IS NULL AND octet_length(s.{text_col}) > 0
                                ORDER BY s.{id_col} ASC
                                LIMIT %s
                                """
                                cur.execute(sql, (batch_size,))
                                rows = cur.fetchall()
                            batch = [(r[0], r[1]) for r in rows]
                        else:
                            batch = []
                    else:
                        batch = fetch_batch(conn, table, id_col, text_col, batch_size)
                    
                    if not batch:
                        print(f"No more rows to process for {table}. Total processed={processed_counter.value}")
                        break
                    
                    # 12. Submit ONE task to the pool and wait for it to complete
                    future = executor.submit(
                        process_batch_worker, 
                        batch, 
                        id_col, text_col, table, prefix, metrics_only, 
                        processed_lock, processed_counter, initial_remaining, True # is_parallel=True
                    )
                    
                    # Wait for this batch to complete before fetching the next
                    try:
                        future.result()
                    except Exception as e:
                        print(f"Worker error: {e}", file=sys.stderr)
                    
                    if sleep_secs > 0:
                        time.sleep(sleep_secs)
        else:
            # Single-threaded mode (runs in the main process)
            while True:
                # Build selection based on mode (Fetching logic is the same as in the loop above)
                if metrics_only:
                    metrics_table_exists = False
                    try:
                        with conn.cursor() as cur:
                            cur.execute("SELECT to_regclass('laana.sentence_metrics')")
                            r = cur.fetchone()
                            metrics_table_exists = bool(r and r[0])
                    except Exception:
                        metrics_table_exists = False

                    if metrics_table_exists:
                        with conn.cursor() as cur:
                            sql = f"""
                            SELECT s.{id_col} AS id, s.{text_col} AS text
                            FROM laana.{table} s
                            LEFT JOIN laana.sentence_metrics m ON m.sentenceid = s.{id_col}
                            WHERE s.embedding IS NOT NULL AND m.sentenceid IS NULL AND octet_length(s.{text_col}) > 0
                            ORDER BY s.{id_col} ASC
                            LIMIT %s
                            """
                            cur.execute(sql, (batch_size,))
                            rows = cur.fetchall()
                        batch = [(r[0], r[1]) for r in rows]
                    else:
                        print(f"DB update failed: table sentence_metrics does not exist", file=sys.stderr)
                        break
                else:
                    batch = fetch_batch(conn, table, id_col, text_col, batch_size)
                
                if not batch:
                    print(f"No more rows to embed for {table}. Processed={processed_counter.value}")
                    break
                
                # Execute worker function in main process (is_parallel=False)
                process_batch_worker(batch, id_col, text_col, table, prefix, metrics_only, processed_lock, processed_counter, initial_remaining, False)
                
                if sleep_secs > 0:
                    time.sleep(sleep_secs)

        conn.close()


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        print("Interrupted")
        sys.exit(130)
    except Exception as e:
        print(f"Fatal error: {e}", file=sys.stderr)
        sys.exit(1)
