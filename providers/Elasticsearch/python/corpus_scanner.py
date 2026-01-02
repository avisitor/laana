import argparse
import hashlib
from collections import defaultdict
from spacy import load as load_spacy
from elasticsearchclient import ElasticsearchDB
from metadata_cache import MetadataCache  # ⬅️ New cache class

class CorpusScanner:
    def __init__(self, config, recreate=False, verbose=False, save_interval=10, client=None, dryrun=False):
        self.config = config
        self.es = client if client else ElasticsearchDB(config)
        self.dryrun = dryrun
        self.nlp = load_spacy("en_core_web_sm")
        self.metric_keys = ["length", "frequency", "boilerplate_score", "entity_count", "word_count", "hawaiian_word_ratio"]
        self.sentence_counts = defaultdict(int)
        self.verbose = verbose
        self.save_interval = save_interval
        self.hawaiian_words = self.load_hawaiian_words()
        print(f"Read {len(self.hawaiian_words)} words into Hawaiian dictionary")

        # 🧠 Read-write cache backed by metadata index
        self.metadata_cache = MetadataCache(self.es, max_size=10000, flush_interval=60)

    @staticmethod
    def _normalize_word(word):
        # Remove okina and apostrophe, and convert macrons to plain vowels
        return word.lower().replace("‘", "").replace("'", "").replace("ā", "a").replace("ē", "e").replace("ī", "i").replace("ō", "o").replace("ū", "u")

    def load_hawaiian_words(self):
        try:
            with open("hawaiian_words.txt", "r") as f:
                all_headwords = set()
                for line in f:
                    # 1. Split by comma for multiple headwords on one line
                    comma_parts = [p.strip() for p in line.split(',')]
                    for part in comma_parts:
                        # 2. Split by whitespace for multi-word headwords
                        words = part.split()
                        for word in words:
                            # 3. Skip 'a' and 'i'
                            if word.lower() not in ['a', 'i']:
                                all_headwords.add(word)
                
                # 4. Normalize all collected words and return as a set for uniqueness
                return {self._normalize_word(word) for word in all_headwords}
        except FileNotFoundError:
            print("Warning: hawaiian_words.txt not found. Hawaiian word ratio will not be calculated.")
            return set()

    @staticmethod
    def hash_sentence(text):
        return hashlib.md5(text.strip().lower().encode("utf-8")).hexdigest()

    def compute_boilerplate_score(self, text, entity_count):
        score = 0
        if len(text) < 40:
            score += 0.5
        if entity_count == 0:
            score += 0.5
        return round(min(score, 1.0), 2)

    def analyze_sentence(self, text, existing_metadata=None):
        h = CorpusScanner.hash_sentence(text)
        meta = existing_metadata or {}

        meta["frequency"] = meta.get("frequency", 0) + 1
        doc = self.nlp(text)
        entity_count = len(doc.ents)
        
        words = text.split()
        word_count = len(words)
        hawaiian_word_count = 0
        diacritic_chars = "āĀēĒīĪōŌūŪ‘"

        for word in words:
            if any(char in word for char in diacritic_chars):
                hawaiian_word_count += 1
            else:
                normalized_word = self._normalize_word(word)
                if normalized_word in self.hawaiian_words:
                    hawaiian_word_count += 1
        
        hawaiian_word_ratio = hawaiian_word_count / word_count if word_count > 0 else 0

        meta.update({
            "length": len(text),
            "entity_count": entity_count,
            "boilerplate_score": self.compute_boilerplate_score(text, entity_count),
            "word_count": word_count,
            "hawaiian_word_ratio": hawaiian_word_ratio
        })

        self.metadata_cache.set(h, meta)
        return meta

    def process_sentence(self, text, doc_id):
        h = CorpusScanner.hash_sentence(text)
        existing_meta = self.metadata_cache.get(h)
        meta = self.analyze_sentence(text, existing_meta)

        if self.verbose:
            print(f"[METADATA] {h} → {text[:100]}...")

    def scan(self):
        total_processed = 0
        for doc in self.es.get_all_sentences():
            doc_id = doc["doc_id"]
            source = doc["_source"].get("sourcename", "UNKNOWN")
            sentences = doc["_source"].get("sentences", [])
            added = 0

            for s in sentences:
                text = s.get("text")
                self.process_sentence(text, doc_id)
                added += 1

            print(f"[SCAN] Doc: {doc_id}, Source: {source}, Sentences processed: {added}")
            total_processed += 1

        self.finish()

    def finish(self):
        self.metadata_cache.finish()

    def fetch_metadata(self):
        metadata = self.es.fetch_all_metadata()
        for h, m in metadata.items():
            print(f"{h}: {m}")

    def show_sentence_metadata(self, doc_id):
        doc = self.es.get_document(doc_id)
        if not doc or "_source" not in doc or "sentences" not in doc["_source"]:
            print(f"⚠️ Document {doc_id} not found or missing sentence data.")
            return

        sentences = doc["_source"]["sentences"]
        print(f"📘 Showing stored metadata for {len(sentences)} sentences in doc {doc_id}:\n")

        for i, s in enumerate(sentences):
            text = s.get("text", "").strip()
            if not text:
                continue

            h = CorpusScanner.hash_sentence(text)
            meta = self.metadata_cache.get(h)

            print(f"📄 Sentence [{i}]")
            print(f"Text: \"{text}\"")
            print(f"Hash: {h}")

            if meta:
                for key in self.metric_keys:
                    print(f"{key.capitalize()}: {meta.get(key, '—')}")
            else:
                print("⚠️ No stored metadata found for this sentence.")
            print("-" * 40)

    def rehash_sentences(self):
        sentence_docs = self.es.get_all_sentences()
        for doc in sentence_docs:
            doc_id = doc["doc_id"]
            sentences = doc["_source"].get("sentences", [])
            updated = []

            for i, s in enumerate(sentences):
                if "sentence_hash" in s:
                    continue

                text = s["text"]
                h = CorpusScanner.hash_sentence(text)

                if "vector" not in s:
                    full_doc = self.es.get_document(doc_id)
                    full_sent = full_doc["_source"]["sentences"][i]
                    full_sent["sentence_hash"] = h
                    updated.append(full_sent)
                else:
                    s["sentence_hash"] = h
                    updated.append(s)

            if updated:
                self.es.update_document(doc_id, {"doc": {"sentences": updated}})
                print(f"🔄 Rehashed doc: {doc_id}")

# ── Entry Point ─────────────────────────────────────
if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--recreate", action="store_true")
    parser.add_argument("--fetch", action="store_true")
    parser.add_argument("--rehash", action="store_true")
    parser.add_argument("--verbose", action="store_true")
    parser.add_argument("--save_interval", type=int, default=10)
    parser.add_argument("--show_sentences", type=int, help="Display full sentence metadata for a specific doc_id")
    args = parser.parse_args()

    config = { "COLLECTION_NAME": "hawaiian_hybrid" }
    scanner = CorpusScanner(config, recreate=args.recreate, verbose=args.verbose, save_interval=args.save_interval)

    if args.recreate:
        scanner.es.recreate_metadata_index()
    elif args.rehash:
        scanner.rehash_sentences()
    elif args.fetch:
        scanner.fetch_metadata()
    elif args.show_sentences:
        scanner.show_sentence_metadata(args.show_sentences)
    else:
        scanner.scan()
