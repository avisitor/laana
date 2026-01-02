import time
t0 = time.time()
import os
os.environ["TOKENIZERS_PARALLELISM"] = "false"
import sys
import warnings
warnings.filterwarnings("ignore", message="The value of the smallest subnormal for <class 'numpy.float32'> type is zero.")
from elasticsearchclient import ElasticsearchDB
from query_builder import QueryBuilder
from corpus_scanner import CorpusScanner
import subprocess
import argparse

parser = argparse.ArgumentParser(description="Query vector and keyword  DB.")
parser.add_argument('query', type=str, nargs='+', help='The user query to search for')
parser.add_argument('--mode', type=str, default='match', choices=["all"] + QueryBuilder.MODES, help='Query mode (default: match)')
parser.add_argument('--num-results', type=int, default=10, help='Number of results to return')
parser.add_argument(
    "--metrics",
    action="store_true",
    help="Include match scores and metadata with each result"
)
parser.add_argument("--similarity-threshold", type=float, default=0.80,
                    help="Minimum cosine similarity for snippet inclusion")
parser.add_argument("--verbose", action="store_true", help="Print the full Elasticsearch query")
args = parser.parse_args()

COLLECTION_NAME = "hawaiian_hybrid"
config = {
    "COLLECTION_NAME": COLLECTION_NAME,
}

query = ' '.join(args.query)
mode = args.mode

def debug(msg):
    print(msg)
    try:
        sys.stdout.flush()
    except Exception:
        pass


def vector_search(collection, query_vec, top_k):
    try:
        results = collection.query(
            query_embeddings=[query_vec],
            n_results=top_k,
            include=["documents", "metadatas"]
        )
        docs = results.get("documents", [[]])[0]
        metas = results.get("metadatas", [[]])[0]
        return [f"{doc}\n[sourceid: {meta.get('sourceid','')}]" for doc, meta in zip(docs, metas)]
    except Exception as e:
        debug(f"[Chroma] Vector search error: {e}")
        return []

def keyword_search(collection, query, top_k, vector_dim):
    try:
        results = collection.query(
            query_texts=[query],
            query_embeddings=[[0.0]*vector_dim],  # disables vector search
            n_results=top_k,
            include=["documents", "metadatas"]
        )
        docs = results.get("documents", [[]])[0]
        metas = results.get("metadatas", [[]])[0]
        return [f"{doc}\n[sourceid: {meta.get('sourceid','')}]" for doc, meta in zip(docs, metas)]
    except Exception as e:
        debug(f"[Chroma] Keyword search error: {e}")
        return []

def hybrid_search(vector_chunks, keyword_chunks, k=60):
    doc2rrf = {}
    for rank, chunk in enumerate(vector_chunks):
        doc2rrf[chunk] = doc2rrf.get(chunk, 0) + 1.0 / (k + rank + 1)
    for rank, chunk in enumerate(keyword_chunks):
        doc2rrf[chunk] = doc2rrf.get(chunk, 0) + 1.0 / (k + rank + 1)
    return [doc for doc, _ in sorted(doc2rrf.items(), key=lambda x: -x[1])]

def _rerank_sentences(search_hits, metadata_cache, quality_weight):
    """
    Re-ranks a list of sentence search hits using their quality scores.

    Args:
        search_hits: A list of search hits from Elasticsearch.
        metadata_cache: An instance of MetadataCache.
        quality_weight: The weight to give to the quality score (0.0 to 1.0).

    Returns:
        A sorted list of re-ranked search hits.
    """
    if not search_hits:
        return []

    all_hits = []
    for sourcename, entries in search_hits.items():
        for entry in entries:
            entry['sourcename'] = sourcename
            all_hits.append(entry)

    relevance_scores = [hit['score'] for hit in all_hits]
    max_relevance_score = max(relevance_scores) if relevance_scores else 0
    if max_relevance_score > 0:
        norm_relevance_scores = [s / max_relevance_score for s in relevance_scores]
    else:
        norm_relevance_scores = [0] * len(relevance_scores)

    quality_scores = []
    for hit in all_hits:
        text = hit['snippets'][0] if hit['snippets'] else ''
        sentence_hash = CorpusScanner.hash_sentence(text)
        metadata = metadata_cache.get(sentence_hash)
        score = 0
        if metadata and 'boilerplate_score' in metadata:
            # The lower the boilerplate_score, the better.
            # So we subtract it from 1.
            score = 1 - metadata.get('boilerplate_score', 0)
        quality_scores.append(score)

    max_quality_score = max(quality_scores) if quality_scores else 0
    if max_quality_score > 0:
        norm_quality_scores = [s / max_quality_score for s in quality_scores]
    else:
        norm_quality_scores = [0] * len(quality_scores)

    relevance_weight = 1.0 - quality_weight
    for i, hit in enumerate(all_hits):
        combined_score = (relevance_weight * norm_relevance_scores[i]) + \
                         (quality_weight * norm_quality_scores[i])
        hit['combined_score'] = combined_score

    reranked_hits = sorted(all_hits, key=lambda x: x['combined_score'], reverse=True)

    result = {}
    for hit in reranked_hits:
        sourcename = hit['sourcename']
        result.setdefault(sourcename, []).append(hit)
    return result


from metadata_cache import MetadataCache

try:
    client = ElasticsearchDB(config)
    metadata_cache = MetadataCache(client)
    client.similarity_threshold = args.similarity_threshold
    print(f"Startup latency: {time.time() - t0:.2f}s")
    
    # Get the query body
    query_builder = QueryBuilder(embedder=client.embed_text)
    query_body = query_builder.build(mode, query, k=args.num_results)
    
    if args.verbose:
        # Print the query body
        import json
        print("--- Elasticsearch Query ---")
        print(json.dumps(query_body, indent=2))
        print("--------------------------")

    response = client.search( query, mode, max_results=args.num_results )
    print(f"Time until search completion: {time.time() - t0:.2f}s")

    if mode in ["vectorsentence", "hybridsentence"]:
        response = _rerank_sentences(response, metadata_cache, 0.3)

    if response is None:
        print(f"No matching data")
    else:
        #print(response)
        # Extract snippets
        # 🧠 Evaluation-friendly display
        all_hits = []
        for sourcename, entries in response.items():
            for entry in entries:
                entry['sourcename'] = sourcename
                all_hits.append(entry)
        
        if mode in ["vectorsentence", "hybridsentence"]:
            all_hits = sorted(all_hits, key=lambda x: x['combined_score'], reverse=True)
        
        for entry in all_hits[:args.num_results]:
            sourceid = entry.get("sourceid", "unknown")
            sourcename = entry.get("sourcename", "unknown")
            score = entry.get("score", "—")
            #print(f"\n📄 Source: {sourceid} {sourcename} Score:{score}")
            #label = entry["mode"]
            extras = ""
            if args.metrics:
                metrics = entry.get("metrics", {})
                if metrics:
                    extras = (
                        f", Cosine Similarity: {metrics.get('cosine_score', '—')},"
                        f" Snippet Length: {metrics.get('snippet_length', '—')},"
                        f" Token Matches: {metrics.get('token_matches', '—')}"
                    )
            print(f"[{entry['mode']}] {sourceid} {sourcename} [Score:{score}{extras}]")
            for snippet in entry["snippets"]:
                print(f"  → {snippet}")
                        
except Exception as e:
    print("Error calling ElasticSearch:", e)


print(f"Time until search results rendered: {time.time() - t0:.2f}s")
