import argparse
from pprint import pprint
import gc
import requests
import warnings
import nltk
from nltk.tokenize import sent_tokenize
from nltk.corpus import words as nltk_words
from elasticsearch import helpers
from elasticsearch.helpers import bulk
from elasticsearchclient import ElasticsearchDB
from corpus_scanner import CorpusScanner  # Your metadata enrichment tool

# Suppress SSL warnings for localhost connections
from urllib3.exceptions import InsecureRequestWarning
warnings.filterwarnings('ignore', category=InsecureRequestWarning)

MIN_DOC_HAWAIIAN_WORD_RATIO = 0.1

# Quietly download necessary resources
nltk.download('punkt', quiet=True)
nltk.download('words', quiet=True)

class CorpusIndexer:
    def __init__(self, config, recreate=False, dryrun=False, verbose=False):
        print("CorpusIndexer __init__")
        self.config = config

        # Pass the recreate flag to the client
        db_config = config.copy()
        db_config["RECREATE_COLLECTION"] = recreate
        self.client = ElasticsearchDB(db_config)

        self.scanner = CorpusScanner(config, recreate=recreate, dryrun=dryrun, client=self.client)
        self.english_vocab = set(nltk_words.words())
        self.recreate = recreate
        self.dryrun = dryrun
        self.verbose = verbose
        self.batch_size = config.get("BATCH_SIZE", 50)
        self.checkpoint_interval = config.get("CHECKPOINT_INTERVAL", 50)
        self.sources = []
        self.meta = self.initialize_metadata()
        if( self.dryrun ):
            pprint( self.meta )

    def initialize_metadata(self):
        if self.recreate:
            print("Recreate flag is set. Initializing with empty metadata.")
            return {
                "no_hawaiian_ids": set(),
                "processed_sourceids": set(),
                "discarded_sourceids": set(),
                "english_only_ids": set(),
            }
        resp = self.client.get_source_metadata()
        if not resp:
            return {
                "no_hawaiian_ids": set(),
                "processed_sourceids": set(),
                "discarded_sourceids": set(),
                "english_only_ids": set(),
            }
        return {
            k: set(v) if isinstance(v, list) else v
            for k, v in resp['_source'].items()
        }

    def fetch_sources(self):
        domain = self.config.get("DOMAIN", "noiiolelo.org")
        url = f"https://{domain}/api.php/sources?details"
        groupname_filter = self.config.get("GROUPNAME_FILTER")
        
        try:
            resp = requests.get(url)
            resp.raise_for_status()
            all_sources = resp.json().get("sources", [])
            
            # Filter by groupname if specified
            if groupname_filter:
                self.sources = [s for s in all_sources if s.get("groupname") == groupname_filter]
                print(f"✅ Fetched {len(self.sources)} sources (filtered by groupname='{groupname_filter}' from {len(all_sources)} total).")
            else:
                self.sources = all_sources
                print(f"✅ Fetched {len(self.sources)} sources.")
        except Exception as e:
            print(f"❌ Failed to fetch sources: {e}")

    def fetch_text(self, sourceid):
        domain = self.config.get("DOMAIN", "noiiolelo.org")
        url = f"https://{domain}/api.php/source/{sourceid}/plain"
        try:
            resp = requests.get(url)
            resp.raise_for_status()
            return resp.json().get("text", "")
        except:
            return None

    def already_skipped(self, sourceid):
        return sourceid in (
            self.meta["processed_sourceids"]
            | self.meta["discarded_sourceids"]
            | self.meta["english_only_ids"]
        )

    def embed_sentences(self, sentences):
        return self.client.embed_sentences(sentences)

    def embed_text(self, text, is_query=True):
        return self.client.embed_text(text, is_query=is_query)

    def checkpoint_metadata(self):
        self.client.save_source_metadata(self.meta)
        gc.collect()
        print("🔄 Metadata checkpoint saved.")

    def process_source(self, source, index_counter):
        sourceid = str(source.get("sourceid"))
        if not self.dryrun and self.already_skipped(sourceid):
            print(f"Skipping already indexed or discarded {sourceid}")
            return None

        print(f"[{index_counter} / {len(self.sources)}] Processing sourceid={sourceid} ({source.get('sourcename', 'N/A')})")

        text = self.fetch_text(sourceid)
        if not text:
            print(f"⚠️ Skipping: No text for {sourceid}")
            self.meta["discarded_sourceids"].add(sourceid)
            return None

        # Document-level Hawaiian word check
        words = text.split()
        word_count = len(words)
        hawaiian_word_count = 0
        diacritic_chars = "āĀēĒīĪōŌūŪ‘"
        for word in words:
            if any(char in word for char in diacritic_chars):
                hawaiian_word_count += 1
            else:
                normalized_word = self.scanner._normalize_word(word)
                if normalized_word in self.scanner.hawaiian_words:
                    hawaiian_word_count += 1
        
        doc_hawaiian_word_ratio = hawaiian_word_count / word_count if word_count > 0 else 0

        if doc_hawaiian_word_ratio < MIN_DOC_HAWAIIAN_WORD_RATIO:
            print(f"⚠️ Skipping: Document {sourceid} has a low Hawaiian word ratio ({doc_hawaiian_word_ratio:.2f}).")
            self.meta["english_only_ids"].add(sourceid)
            return None

        # Split text and embed
        sentence_texts = sent_tokenize(text)
        
        sentences = []
        for s_text in sentence_texts:
            # Analyze the sentence and save the metrics for it in the database
            metrics = self.scanner.analyze_sentence(s_text)
            if metrics.get("hawaiian_word_ratio", 0) >= 0.5:
                sentences.append(s_text)

        if not sentences:
            print(f"⚠️ Skipping: No Hawaiian sentences found for {sourceid}")
            self.meta["english_only_ids"].add(sourceid)
            return None

        sentence_vectors = self.embed_sentences(sentences)
        text_vector = self.embed_text(text, is_query=False)

        # Sentence-level objects with doc_id
        sentence_objects = []
        for idx, (s_text, s_vec) in enumerate(zip(sentences, sentence_vectors)):
            sentence_obj = {
                "text": s_text,
                "vector": s_vec,
                "position": idx,
                "doc_id": sourceid
            }
            sentence_objects.append(sentence_obj)
            
        # Document-level object (also with doc_id)
        doc = {
            "_index": self.client.collection,
            "_id": sourceid,
            "_source": {
                "doc_id": sourceid,
                "groupname": source.get("groupname", "N/A"),
                "sourcename": source.get("sourcename", "N/A"),
                "text": text,
                "text_vector": text_vector,
                "text": text,
                "sentences": sentence_objects,
                "date": source.get("date", ""),
                "authors": source.get("authors", ""),
                "hawaiian_word_ratio": doc_hawaiian_word_ratio
            },
        }

        self.meta["processed_sourceids"].add(sourceid)
        return doc

    def run_indexing(self):
        DRY_RUN_LIMIT = 3
        self.fetch_sources()

        sources_to_process = self.sources
        if self.dryrun:
            print(f"--- DRY RUN MODE: Processing up to {DRY_RUN_LIMIT} sources ---")
            sources_to_process = self.sources[:DRY_RUN_LIMIT]

        indexed_total = 0
        global_i = 0

        for i in range(0, len(sources_to_process), self.batch_size):
            chunk = sources_to_process[i : i + self.batch_size]
            actions = []
            for idx, source in enumerate(chunk):
                global_i += 1
                global_idx = i + idx + 1  # actual source position in self.sources
                doc = self.process_source(source, index_counter=global_idx)
                if doc:
                    actions.append(doc)

            if actions:
                if self.dryrun:
                    print(f"--- DRY RUN: Found {len(actions)} documents to index in this batch ---")
                    if self.verbose:
                        print("--- Verbose output enabled ---")
                        pprint(actions)
                        print("------------------------------")
                    else:
                        for action in actions:
                            print(f"  - Would index doc id: {action['_id']}, sourcename: {action['_source']['sourcename']}")
                else:
                    helpers.bulk(self.client.client, actions)
                    print(f"📦 Bulk indexed {len(actions)} documents.")
                    indexed_total += len(actions)

                    if global_i % self.checkpoint_interval == 0:
                        self.checkpoint_metadata()

        # Final flush
        if not self.dryrun:
            self.checkpoint_metadata()
            self.scanner.finish()

        print(f"✅ Indexing complete. Total indexed: {indexed_total}")

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--recreate", action="store_true")
    parser.add_argument("--dryrun", action="store_true")
    parser.add_argument("--verbose", action="store_true", help="Print full document data during a dry run")
    parser.add_argument("--groupname", type=str, help="Filter sources by groupname")
    parser.add_argument("--domain", type=str, default="noiiolelo.org", help="Domain to fetch from (default: noiiolelo.org)")
    args = parser.parse_args()

    config = {
        "COLLECTION_NAME": "hawaiian",
        "CHECKPOINT_INTERVAL": 50,
        "BATCH_SIZE": 5,
        "DOMAIN": args.domain,
        "GROUPNAME_FILTER": args.groupname
    }

    indexer = CorpusIndexer(config, recreate=args.recreate, dryrun=args.dryrun, verbose=args.verbose)
    indexer.run_indexing()

if __name__ == "__main__":
    main()
