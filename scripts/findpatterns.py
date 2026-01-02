import os
import re
import argparse
import psycopg2
from psycopg2.extras import execute_values
import pymysql
from dotenv import load_dotenv
import sys
import time

import json

# 1. Load credentials
load_dotenv('/var/www/html/noiiolelo/.env')

def get_connection(provider='Postgres'):
    if provider == 'Postgres':
        conn = psycopg2.connect(
            host=os.getenv('PG_HOST'),
            port=os.getenv('PG_PORT'),
            database=os.getenv('PG_DATABASE'),
            user=os.getenv('PG_USER'),
            password=os.getenv('PG_PASSWORD')
        )
        # Autocommit allows us to see progress in the DB immediately 
        # and keeps the script resilient to interruptions.
        conn.autocommit = True
    elif provider == 'MySQL':
        conn = pymysql.connect(
            host=os.getenv('DB_HOST', 'localhost'),
            port=int(os.getenv('DB_PORT', 3306)),
            database=os.getenv('DB_DATABASE', 'laana'),
            user=os.getenv('DB_USER'),
            password=os.getenv('DB_PASSWORD'),
            charset='utf8mb4',
            autocommit=True
        )
    else:
        raise ValueError(f"Invalid provider: {provider}. Must be 'Postgres' or 'MySQL'.")
    return conn

# 2. Define linguistic patterns
# Load patterns from shared JSON file
try:
    with open('/var/www/html/noiiolelo/lib/grammar_patterns.json', 'r') as f:
        PATTERNS = json.load(f)
except Exception as e:
    print(f"Error loading patterns from JSON: {e}")
    sys.exit(1)

def generate_fingerprint(text):
    """Creates a 'DNA' string of grammatical markers."""
    multi_markers = ['i ka', 'i ke', 'ma ka', 'ma ke', 'e ana', 'ua pau']
    single_markers = ['he', 'o', 'ma', 'i', 'no', 'na', 'ai', 'ana', 'ua', 'ke', 'aia', 'aole', 'hiki', 'nei', 'ala', 'mai', 'aku', 'iho', 'ae']
    
    text_clean = text.lower().strip()
    text_no_punct = re.sub(r'[^\w\sʻāēīōū]', '', text_clean)
    
    found = []
    remaining_text = text_no_punct
    for m in multi_markers:
        if m in remaining_text:
            found.append(m.replace(' ', '_'))
            remaining_text = remaining_text.replace(m, ' ')
    
    tokens = re.findall(r"[\wʻāēīōū]+", remaining_text)
    for t in tokens:
        if t in single_markers:
            found.append(t)
    return "-".join(found) if found else "plain"

def scan_grammar(force=False, provider='Postgres'):
    conn = get_connection(provider)
    cur = conn.cursor()

    # Determine schema/table prefix based on provider
    if provider == 'Postgres':
        table_prefix = 'laana.'
    else:  # Laana (MySQL)
        table_prefix = ''

    if force:
        print(f"Force flag detected. Truncating {table_prefix}sentence_patterns...")
        cur.execute(f"TRUNCATE TABLE {table_prefix}sentence_patterns")

    # Get the max sentence ID to set our boundary
    cur.execute(f"SELECT MAX(sentenceid) FROM {table_prefix}sentences")
    max_id = cur.fetchone()[0] or 0
    
    current_id = 0
    batch_size = 5000
    total_new_processed = 0
    total_new_patterns = 0

    print(f"Scanning sentences by ID range (0 to {max_id})...")

    start_time = time.time()
    while current_id <= max_id:
        # SMART DELTA FETCH: 
        # 1. Look only at a specific ID range (very fast with PK index)
        # 2. Skip any sentence that already has at least one entry in sentence_patterns
        cur.execute(f"""
            SELECT s.sentenceid, s.hawaiiantext 
            FROM {table_prefix}sentences s
            WHERE s.sentenceid > %s AND s.sentenceid <= %s
            AND NOT EXISTS (
                SELECT 1 FROM {table_prefix}sentence_patterns p 
                WHERE p.sentenceid = s.sentenceid
            )
        """, (current_id, current_id + batch_size))
        
        rows = cur.fetchall()
        
        # If rows is empty, it means this whole block of 5000 IDs is already processed.
        if not rows:
            current_id += batch_size
            elapsed = time.time() - start_time
            rate = current_id / elapsed if elapsed > 0 else 0
            print(f"Skipping processed block: {current_id}/{max_id} ({current_id/max_id*100:.1f}%) - {rate:.0f} IDs/sec", end="\r")
            sys.stdout.flush()
            continue
            
        matches = []
        for sid, text in rows:
            total_new_processed += 1
            if not text: continue
            
            clean_text = text.strip()
            fingerprint = generate_fingerprint(clean_text)
            
            for p_type, meta in PATTERNS.items():
                if re.search(meta['regex'], clean_text, re.IGNORECASE):
                    combined_sig = f"{meta['signature']} | {fingerprint}"
                    matches.append((sid, p_type, combined_sig))

        if matches:
            if provider == 'Postgres':
                execute_values(cur, f"""
                    INSERT INTO {table_prefix}sentence_patterns (sentenceid, pattern_type, signature)
                    VALUES %s ON CONFLICT (sentenceid, pattern_type) DO NOTHING
                """, matches)
            else:  # Laana (MySQL)
                # MySQL uses INSERT IGNORE for conflict handling
                placeholders = ','.join(['(%s, %s, %s)'] * len(matches))
                flat_values = [item for match in matches for item in match]
                cur.execute(f"""
                    INSERT IGNORE INTO {table_prefix}sentence_patterns (sentenceid, pattern_type, signature)
                    VALUES {placeholders}
                """, flat_values)
            total_new_patterns += len(matches)

        current_id += batch_size
        elapsed = time.time() - start_time
        rate = current_id / elapsed if elapsed > 0 else 0
        print(f"Progress: {current_id}/{max_id} ({current_id/max_id*100:.1f}%) | New Sentences: {total_new_processed} | New Patterns: {total_new_patterns} | Rate: {rate:.0f} IDs/sec", end="\r")
        sys.stdout.flush()

    print(f"\n\n--- Scan Complete ---")
    print(f"Sentences newly analyzed: {total_new_processed}")
    print(f"Total pattern records created: {total_new_patterns}")
    
    # FINAL SUMMARY REPORT
    print("\n--- Current Pattern Distribution (Full Table) ---")
    cur.execute(f"""
        SELECT pattern_type, COUNT(*) as count 
        FROM {table_prefix}sentence_patterns 
        GROUP BY pattern_type 
        ORDER BY count DESC
    """)
    summary = cur.fetchall()
    
    print(f"{'Pattern Type':<25} | {'Count':<10}")
    print("-" * 40)
    for p_type, count in summary:
        print(f"{p_type:<25} | {count:<10}")

    cur.close()
    conn.close()

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Delta-aware Hawaiian grammar scanner.")
    parser.add_argument('--force', action='store_true', help="Clear pattern table before running")
    parser.add_argument('--provider', type=str, default='Postgres', choices=['Postgres', 'MySQL'],
                        help="Database provider to use (default: Postgres)")
    args = parser.parse_args()

    try:
        scan_grammar(force=args.force, provider=args.provider)
    except KeyboardInterrupt:
        print("\n\nUser interrupted scan. Progress saved.")
    except Exception as e:
        print(f"\n\nAn error occurred: {e}")
    