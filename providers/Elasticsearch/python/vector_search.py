import argparse
import os
import requests
import json
from sentence_transformers import SentenceTransformer

from config_loader import config_loader
# ------------------ Config ------------------

MODEL_NAMES = {
    'all-MiniLM-L6-v2': 384,
    'BERT-base': 768,
    'ELSER': 512,
    'intfloat/multilingual-e5-small': 384,
    'OpenAI Ada v2': 1536,
}

TRANSFORMER_MODEL = 'intfloat/multilingual-e5-small'
DIMS = MODEL_NAMES[TRANSFORMER_MODEL]
ES_URL = "https://localhost:9200/hawaiian_hybrid/_search"
CERT_PATH = "/etc/elasticsearch/certs/http_ca.crt"
ES_USER = "elastic"
ES_PASSWORD = os.environ.get("ELASTIC_PASSWORD")

# ------------------ Model ------------------

def load_or_download_model(model_name, base_dir="local_models"):
    folder_name = model_name.replace("/", "-")
    model_dir = os.path.join(base_dir, folder_name)

    if os.path.exists(model_dir):
        print(f"🔹 Loading model from {model_dir}...")
        return SentenceTransformer(model_dir)
    else:
        print(f"⬇️ Downloading model '{model_name}' from Hugging Face...")
        model = SentenceTransformer(model_name)
        model.save(model_dir)
        return model

def embed_text(model, text):
    return model.encode(text, convert_to_numpy=True).tolist()

# ------------------ Query Builders ------------------

def build_script_score_query(vector):
    return {
        "size": 5,
        "query": {
            "script_score": {
                "query": {
                    "bool": {
                        "filter": [
                            { "exists": { "field": "text" } },
                            {
                                "script": {
                                    "script": {
                                        "source": "doc[\"text.keyword\"].size() != 0 && doc[\"text.keyword\"].value != \"\"",
                                        "lang": "painless"
                                    }
                                }
                            }
                        ]
                    }
                },
                "script": {
                    "source": "cosineSimilarity(params.query_vector, \"text_vector\") + 1.0",
                    "params": {
                        "query_vector": vector
                    }
                }
            }
        },
        "_source": ["sourcename", "text"]
    }

def build_knn_query(vector):
    return {
        "size": 5,
        "knn": {
            "field": "text_vector",
            "query_vector": vector,
            "k": 5,
            "num_candidates": 100
        },
        "_source": ["sourcename", "text"]
    }

# ------------------ Execution ------------------

def send_query(payload):
    response = requests.post(
        ES_URL,
        json=payload,
        auth=(ES_USER, ES_PASSWORD),
        verify=CERT_PATH,
        headers={"Content-Type": "application/json"}
    )
    return response.json()

def print_results(label, result):
    print(f"\n=== {label} Results ===")
    for hit in result.get("hits", {}).get("hits", []):
        src = hit["_source"]
        score = hit.get("_score", "—")
        print(f"• {src['sourcename']} — Score: {score}")
        preview = src.get("text", "").replace("\n", " ")
        print(f"  ↪ {preview[:150]}...\n")

# ------------------ CLI ------------------

def main():
    parser = argparse.ArgumentParser(description="Compare Vector Search Methods")
    parser.add_argument("query", type=str, help="Query text to search for")
    args = parser.parse_args()

    model = load_or_download_model(TRANSFORMER_MODEL)
    vector = embed_text(model, args.query)

    print(f"\n🔍 Searching for: {args.query}")

    # Script Score
    ss_payload = build_script_score_query(vector)
    ss_results = send_query(ss_payload)
    print_results("Script Score", ss_results)

    # Native KNN
    knn_payload = build_knn_query(vector)
    knn_results = send_query(knn_payload)
    print_results("Native KNN", knn_results)

if __name__ == "__main__":
    main()
