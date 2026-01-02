
from config_loader import config_loader
# Creates and searches an Elastic Search full text database that also has a vector index
import os
import sys
import array
import warnings
import requests
import argparse
import gc
import json
import ssl
import re
import time
import traceback
from pprint import pprint
from dotenv import load_dotenv
from elasticsearch import Elasticsearch
from elasticsearch.helpers import scan
from elasticsearch.helpers import bulk
from query_builder import QueryBuilder
from numpy import dot
from numpy.linalg import norm

def cosine_similarity(a, b):
    return dot(a, b) / (norm(a) * norm(b))

load_dotenv()

# Replace with your Elasticsearch host and port
ES_HOST = "localhost"
ES_PORT = 9200
#API_KEY = "RjVpRVlwZ0JEVjU4c3FDNHpLNDI6c2Z3SnVndlpGanhnUGtpYTFtTlk0dw==" #hawaiian-hybrid-api_key
api_key=os.environ['API_KEY']
# Models and dimensions
MODEL_CONFIG = {
    'all-MiniLM-L6-v2': {
        'dims': 384,
        'query_prefix': '',
        'passage_prefix': ''
    },
    'BERT-base': {
        'dims': 768,
        'query_prefix': '',
        'passage_prefix': ''
    },
    'ELSER': {
        'dims': 512,
        'query_prefix': '',
        'passage_prefix': ''
    },
    'intfloat/multilingual-e5-small': {
        'dims': 384,
        'query_prefix': 'query: ',
        'passage_prefix': 'passage: '
    },
    'OpenAI Ada v2': {
        'dims': 1536,
        'query_prefix': '',
        'passage_prefix': ''
    },
}
TRANSFORMER_MODEL = 'intfloat/multilingual-e5-small'
DIMS = MODEL_CONFIG[TRANSFORMER_MODEL]['dims']
t0 = time.time()

def load_or_download_model(model_name, base_dir="local_models"):
    from sentence_transformers import SentenceTransformer
    # Sanitize model name to create a valid folder name
    folder_name = model_name.replace("/", "-")
    model_dir = os.path.join(base_dir, folder_name)

    if os.path.exists(model_dir):
        print(f"🔹 Loading model from {model_dir}...")
        model = SentenceTransformer(model_dir)
    else:
        print(f"⬇️ Downloading model '{model_name}' from Hugging Face...")
        model = SentenceTransformer(model_name)
        model.save(model_dir)
        print(f"Time until sentence transformer loaded: {time.time() - t0:.2f}s")
    return model

class ElasticsearchDB():
    def __init__(self, config):
        print("ElasticsearchDB __init__")
        #traceback.print_stack()
        
        # When using API key authentication, we can skip SSL certificate verification
        # for localhost connections or use fingerprint verification
        self.client = Elasticsearch(
            hosts=[{"host": ES_HOST, "port": ES_PORT, "scheme": "https"}],
            verify_certs=False,  # Disable cert verification when using API key
            request_timeout=120,
            api_key=api_key
        )
        client_info = self.client.info()
        print('Connected to Elasticsearch!')
        pprint(client_info.body)

        self.config = config
        if "COLLECTION_NAME" in self.config:
            self.collection = config["COLLECTION_NAME"]
        else:
            self.collection = "hawaiian"
        self.metadata = self.collection + "-metadata"
        self.metadataid = "all"
        self.source_metadata = self.collection + "source--metadata"
        self.source_metadataid = "all"
        self.model = None;
        if "RECREATE_COLLECTION" in self.config and self.config["RECREATE_COLLECTION"]:
            try:
                self.client.indices.delete(index=self.collection)
                self.client.indices.delete(index=self.metadata)
                self.client.indices.delete(index=self.source_metadata)
                print(f"Deleted existing collection '{self.collection}'.")
            except Exception as e:
                print(f"Warning: Could not delete collection '{self.collection}': {e}")

        if not self.client.indices.exists(index=self.collection):
            self.create_index()
        else:
            # Check for model compatibility
            index_mapping = self.client.indices.get_mapping(index=self.collection)
            stored_model = index_mapping[self.collection]['mappings'].get('_meta', {}).get('model')
            if stored_model and stored_model != TRANSFORMER_MODEL:
                print("!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!")
                print("! WARNING: Model mismatch detected.")
                print(f"! Index was created with: {stored_model}")
                print(f"! Current model is:     {TRANSFORMER_MODEL}")
                print("! Vector search modes (vectorsentence, hybridsentence, vector, hybrid, knn) will produce incorrect results.")
                print("! Please re-index your data with 'python createindex.py --recreate' to fix this.")
                print("!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!")

        if not self.client.indices.exists(index=self.metadata):
            self.create_metadata_index()

    def create_index(self):
        # Create index with custom mapping
        self.client.indices.create(
            index=self.collection,
            body={
                "mappings": {
                    "_meta": {
                        "model": TRANSFORMER_MODEL
                    },
                    "properties": {
                        "groupname": {"type": "keyword"},
                        "sourcename": {"type": "keyword"},
                        "date": {"type": "date"},
                        "authors": {"type": "keyword"},
                        "hawaiian_word_ratio": {"type": "float"},
                        "text": {
                            "type": "text",
                            "fields": {
                                "keyword": {
                                    "type": "keyword",
                                    "ignore_above": 256
                                }
                            }
                        },
                        "doc_id": {
                            "type": "keyword"
                        },
                        "text": {
                            "type": "wildcard"
                        },
                        "text_vector": {
                            "type": "dense_vector",
                            #"dims": DIMS,  # dims are now automatically assigned on first document indexing
                            "index": True,
                            "similarity": "cosine"
                        },
                        "sentences": {
                            "type": "nested",
                            "properties": {
                                "text": { "type": "text" },
                                "position": { "type": "integer" },
                                "vector": {
                                    "type": "dense_vector",
                                    "index": True,
                                    "similarity": "cosine"
                                }
                            }
                        }

                    }
                }
            }
        )

    def create_metadata_index(self):
        self.client.indices.create(
            index=self.metadata,
            body={
                "mappings": {
                    "properties": {
                        "doc_id": {
                            "type": "keyword"
                        },
                        "sentence_hash": { "type": "keyword" },
                        "frequency": { "type": "integer" },
                        "metadata": {
                            "type": "object",
                            "enabled": True
                            # example: { "doc_ids": [...], "positions": [...] }
                        }
                    }
                }
            }
        )

    def get_metadata(self, sentence_hash):
        try:
            return self.client.get(index=self.metadata, id=sentence_hash)["_source"]
        except:
            return None

    def get_document(self, doc_id):
        try:
            response = self.client.get(index=self.collection, id=str(doc_id))
            return response
        except Exception as e:
            print(f"⚠️ Failed to fetch doc {doc_id}: {e}")
            return None

    def iter_docs(self, query, page_size=1000, source_fields=None, sort_field="doc_id"):
        search_after = None

        while True:
            body = {
                "size": page_size,
                "query": query,
                "sort": [{sort_field: "asc"}],
            }

            if search_after:
                body["search_after"] = search_after

            if source_fields is not None:
                body["_source"] = source_fields

            resp = self.client.search(index=self.collection, body=body)
            hits = resp["hits"]["hits"]

            if not hits:
                break

            for doc in hits:
                yield doc

            search_after = hits[-1]["sort"]

    def get_docs(self, query ):
        # Use scan to iterate over all documents
        return scan(
            self.client,
            index=self.collection,
            query=query,
            size=100,
            scroll="5m"  # ⬅️ Increase to 5 minutes or more
        )
        
    def get_all(self):
        query = {
            "match_all": {}
        }
        return self.iter_docs(query, source_fields=["sentences.text"])

    def get_all_sentences(self):
        query = {
            "match_all": {}
        }
        return self.iter_docs(query)

    def fetch_metadata(self, page_size=100, scroll_duration="2m"):
        query = {
            "match_all": {}
        }

        # Initial search with scroll
        response = self.client.search(
            index=self.collection,
            scroll=scroll_duration,
            size=page_size,
            body={
                "query": query,
                "_source": ["metadata_hash"]
            }
        )

        scroll_id = response.get('_scroll_id')
        hits = response["hits"]["hits"]

        while hits:
            start_time = time.perf_counter()
            for doc in hits:
                yield doc
            response = self.client.scroll(
                scroll=scroll_duration,
                scroll_id=scroll_id
            )
            end_time = time.perf_counter()
            elapsed_time = end_time - start_time
            print(f"Elapsed time: {elapsed_time:.4f} seconds")
            print(f"fetch_metadata: retrieved page ({page_size} sentences")
            scroll_id = response.get('_scroll_id')
            hits = response["hits"]["hits"]

        # Cleanup scroll context
        self.client.clear_scroll(scroll_id=scroll_id)

    def fetch_metadata_hashes(self, page_size=1000):
        #query = {
        #    "match_all": {}
        #}
        #return self.iter_docs(query, page_size=page_size, source_fields=["metadata_hash"])

        return list(self.fetch_metadata())

    def fetch_metadata_count(self):
        # Perform the count query
        response = self.client.count(index=self.metadata)
        sentence_count = response['count']
        return sentence_count

    def update_document(self, key, body):
        # Perform the update
        response = self.client.update(index=self.collection, id=key, body=body)        
        return response
    
    def embed_text(self, text, is_query=True):
        if self.model is None:
            self.model = load_or_download_model(TRANSFORMER_MODEL)
        
        model_config = MODEL_CONFIG.get(TRANSFORMER_MODEL, {})
        if is_query:
            prefix = model_config.get('query_prefix', '')
        else:
            prefix = model_config.get('passage_prefix', '')
            
        return self.model.encode(f'{prefix}{text}', convert_to_numpy=True).tolist()

    def embed_sentences(self, sentences):
        if self.model is None:
            self.model = load_or_download_model(TRANSFORMER_MODEL)
        
        model_config = MODEL_CONFIG.get(TRANSFORMER_MODEL, {})
        prefix = model_config.get('passage_prefix', '')
        
        prefixed_sentences = [f"{prefix}{s}" for s in sentences]
        return self.model.encode(prefixed_sentences)  # using a vector model that supports batch encoding

    def get_source_metadata(self):
        try:
            results = self.client.get(index=self.source_metadata, id=self.source_metadataid)
            return results
        except Exception as e:
            print(f"Warning: Failed to retrieve metadata: {e}")

    def recreate_metadata_index(self):
        if self.client.indices.exists(index=self.metadata):
            self.client.indices.delete(index=self.metadata)
        self.create_metadata_index()

    def update_metadata(self, buffer):
        actions = [
            {
                "_index": self.metadata,
                "_id": h,
                "_source": metadata
            }
            for h, metadata in buffer.items()
        ]
        bulk(self.client, actions)

    def fetch_all_metadata(self):
        query = { "query": { "match_all": {} } }
        results = self.client.search(index=self.metadata, body=query, size=10000)
        return {doc["_source"]["doc_id"]: doc["_source"] for doc in results["hits"]["hits"]}

    def search(self, query_text, mode="match", max_results=10, snippet_size=150, number_of_fragments=3):

        CANDIDATES_TO_FETCH_MULTIPLIER = 10
        candidates_to_fetch = max_results * CANDIDATES_TO_FETCH_MULTIPLIER

        def show_hit( hit ):
            # 👀 DEBUG: Dump inner_hits for inspection
            doc_id = hit["_id"]
            inner_hits = hit.get("inner_hits", {}).get("matched_sentences", {}).get("hits", {}).get("hits", [])
            print(f"\n--- Inner Hits for Doc {doc_id} ---")
            for ih in inner_hits:
                pprint( ih["_source"] )
                #snippet_text = ih.get("_source", {}).get("text", "⚠️ Missing text")
                snippet_text = ih["_source"].get("text", "⚠️ Missing text")
                #snippet_text = ih.get("_source", {}).get("sentences", {}).get("text", "⚠️ Missing text")
                print(f"Matched Sentence: {snippet_text}")
            
        def extract_snippets(hit, query_text, query_vector=None, mode="default", snippet_size=150):
            print( f"extract_snippets mode: {mode}" )
            highlight = hit.get("highlight", {})
            highlight_field = next(iter(highlight), "text")
            snippets = highlight.get(highlight_field, [])

            if snippets:
                return snippets

            # Vector scoring mode: Use inner_hits similarity
            if mode in ["vectorsentence", "hybridsentence"] and query_vector:
                print(f"custom cosine_similarity sorting for {mode}")
                print(f"query_vector: {query_vector[:5]}")  # Check the shape and values
                inner_hits = hit.get("inner_hits", {}).get("matched_sentences", {}).get("hits", {}).get("hits", [])
                scored = []
                for ih in inner_hits:
                    #pprint(ih["_source"])
                    source = ih.get("_source", {})
                    text = source.get("text", "[no text]")
                    vector = source.get("vector")
                    if vector:
                        sim = cosine_similarity(query_vector, vector)
                        print(f"Similarity score: {sim:.4f} for sentence: {text}")
                        if( sim >= self.similarity_threshold ):
                            scored.append((text, sim))
                        else:
                            print(f"discarded because < {self.similarity_threshold}")

                if scored:
                    top_snippets = sorted(scored, key=lambda x: x[1], reverse=True)[:3]
                    return [text for text, _ in top_snippets]

            # Fallback: simple phrase match in full text
            full_text = hit.get("_source", {}).get(highlight_field, "")
            clean_text = full_text.replace("\n", " ")
            phrase = query_text.strip("*")

            match = re.search(re.escape(phrase), clean_text, flags=re.IGNORECASE)
            if match:
                start = max(match.start() - snippet_size // 2, 0)
                end = min(match.end() + snippet_size // 2, len(clean_text))
                return [clean_text[start:end].strip()]
            elif clean_text:
                return [clean_text[:snippet_size].strip()]
            else:
                return []

        def run_subquery(sub_mode):
            try:
                highlight_field = "text" if sub_mode == "wildcard" else "text"
                query_builder = QueryBuilder(embedder=self.embed_text)
                #query_body = query_builder.build(sub_mode, query_text)
                query_body = query_builder.build(
                    sub_mode,
                    query_text,
                    k=candidates_to_fetch
                )
                if sub_mode in ["vector", "hybrid", "knn", "vectorsentence", "hybridsentence"]:
                    query_vector = self.embed_text(query_text)  # or wherever you're storing it
                else:
                    query_vector = None
                # Wrap if needed
                #if "query" not in query_body:
                #    query_body = { "query": query_body }

                # Add highlight and source fields
                query_body.update({
                    "highlight": {
                        "fields": {
                            highlight_field: {
                                "fragment_size": snippet_size,
                                "number_of_fragments": number_of_fragments,
                            }
                        }
                    },
                    "_source": ["sourcename", highlight_field]
                })
                #pprint(query_body)

                response = self.client.search(
                    index=self.collection,
                    size=candidates_to_fetch,
                    body=query_body
                )
                hits = response.body.get("hits", {}).get("hits", [])
                result = {}

                if sub_mode in ["vectorsentence", "hybridsentence"]:
                    all_sentence_hits = []
                    for hit in hits:
                        doc_id = hit["_id"]
                        sourcename = hit.get("_source", {}).get("sourcename", doc_id)
                        inner_hits = hit.get("inner_hits", {}).get("matched_sentences", {}).get("hits", {}).get("hits", [])
                        for inner_hit in inner_hits:
                            sentence_text = inner_hit["_source"].get("text", "")
                            sentence_score = inner_hit["_score"]
                            all_sentence_hits.append({
                                "sourceid": doc_id,
                                "sourcename": sourcename,
                                "mode": sub_mode,
                                "score": sentence_score,
                                "snippets": [sentence_text],
                                "metrics": {}
                            })
                    
                    all_sentence_hits.sort(key=lambda x: x['score'], reverse=True)

                    for s_hit in all_sentence_hits:
                        sourcename = s_hit.pop("sourcename")
                        result.setdefault(sourcename, []).append(s_hit)
                    return result

                for hit in hits:

                    #show_hit( hit )
                    
                    doc_id = hit["_id"]
                    score = hit.get("_score", "—")
                    sourcename = hit.get("_source", {}).get("sourcename", doc_id)
                    snippets = extract_snippets(hit, query_text, snippet_size=snippet_size, mode=sub_mode, query_vector=query_vector)

                    metrics = {}
                    if snippets:
                        # 🚀 Add snippet length
                        metrics["snippet_length"] = len(snippets[0])  # assuming first snippet

                        # 🔍 Count matched tokens
                        cleaned_query = query_text.strip("*")
                        token_pattern = re.compile(r"\b" + re.escape(cleaned_query) + r"\b", flags=re.IGNORECASE)
                        token_count = sum(len(token_pattern.findall(snippet)) for snippet in snippets)
                        metrics["token_matches"] = token_count

                        # 🧠 Vector cosine similarity (for vector modes only)
                        if sub_mode in ["vector", "hybrid", "knn"]:
                            metrics["cosine_score"] = round(score - 1.0, 4)  # undo the +1.0 from your script_score
                        result.setdefault(sourcename, []).append({
                            "sourceid": doc_id,
                            "mode": sub_mode,
                            "score": score,
                            "snippets": snippets,
                            "metrics": metrics
                        })
                return result
            except Exception as e:
                print(f"Warning: Failed to resolve query ({sub_mode}): {e}")
                return {}

        if mode == "all":
            combined = {}
            for sub_mode in QueryBuilder.MODES:
                sub_results = run_subquery(sub_mode)
                for sourcename, entries in sub_results.items():
                    combined.setdefault(sourcename, []).extend(entries)
            return combined
        else:
            return run_subquery(mode)

            
    # This is not necessary since we save the list of processed sourceids in the metadata
    # index
    def get_processed_sourceids(self):
        scroll_time = '2m' # How long Elasticsearch keeps the scroll context alive
        batch_size = 1000
        all_ids = set()

        # Initial search with scroll
        try:
            response = self.client.search(
                index=self.collection,
                body={"query": {"match_all": {}}, "_source": False, "size": batch_size},
                scroll=scroll_time,
                
            )

            # Collect IDs from initial batch
            scroll_id = response['_scroll_id']
            hits = response['hits']['hits']
            all_ids.update(hit['_id'] for hit in hits)

            # Continue scrolling until no more results
            while hits:
                response = self.client.scroll(
                    scroll_id=scroll_id,
                    scroll=scroll_time
                )
                scroll_id = response['_scroll_id']
                hits = response['hits']['hits']
                all_ids.update(hit['_id'] for hit in hits)

                # Clean up scroll context
                try:
                    self.client.clear_scroll(scroll_id=scroll_id)
                except Exception as e:
                    print(f"Warning: Failed to clear scroll: {e}")
        except Exception as e:
            print(f"Warning: no saved source IDs: {e}")

        return all_ids

    def index_document(self, source, text):
        text_vector = self.embed_text(text)  # returns a list[float] of shape (384,)
        sourceid = str(source.get('sourceid')) # Extract and convert sourceid
        sourcename = source.get('sourcename', 'N/A') # Extract sourcename
        groupname = source.get('groupname', 'N/A') # Extract sourcename
        date = source.get('date', 'N/A') # Extract sourcename
        authors = source.get('authors', 'N/A') # Extract sourcename
        doc = {
            'groupname': groupname,
            'sourcename': sourcename,
            'text': text,
            'text': text,  # new field for wildcard queries
            'text_vector': text_vector,  # 👈 this line populates the vector field
            'date': date,
            'authors': authors,
        }
        resp = self.client.index(index=self.collection, id=sourceid, document=doc)
        return resp

    def save_source_metadata(self, info):
        doc = {
            "no_hawaiian_ids": list(info["no_hawaiian_ids"]),
            "processed_sourceids": list(info["processed_sourceids"]),
            "discarded_sourceids": list(info["discarded_sourceids"]),
            "english_only_ids": list(info["english_only_ids"]),
        }
        print(f"save_metadata: {len(info['discarded_sourceids'])} discarded, {len(info['processed_sourceids'])} processed")
        resp = self.client.index(index=self.source_metadata, id=self.source_metadataid, document=doc)
        return resp

    def index_sentence(self, source, sentence, pagenr):
        sourceid = str(source.get('sourceid')) # Extract and convert sourceid
        sourcename = source.get('sourcename', 'N/A') # Extract sourcename
        groupname = source.get('groupname', 'N/A') # Extract sourcename
        doc = {
            'groupname': groupname,
            'sourcename': sourcename,
            'page': pagenr,
            'text': sentence,
        }
        id = str(sourceid) + ":" + str(pagenr)
        resp = self.client.index(index=self.collection, id=id, document=doc)
        return resp

    def index_sentences( self, source, text ):
        sentences = sent_tokenize(text)
        pagenr = 0
        for s in sentences:
            has_english = False
            has_hawaiian = False
            if is_hawaiian(s):
                has_hawaiian = True
            else:
                has_english = True

                # Only index if there is Hawaiian text
                if has_hawaiian:
                    resp = self.index_sentence(source, sentence, pagenr)
                    print(resp['result'] + " for " + id)
            pagenr = pagenr + 1

