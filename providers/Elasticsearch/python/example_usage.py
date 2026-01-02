#!/usr/bin/env python3
"""
Python Example Usage
"""

from config_loader import config_loader
from elasticsearch import Elasticsearch

# Create ES client
es = Elasticsearch(['https://localhost:9200'], api_key='your_key', verify_certs=False)

# Index creation using shared config
index_params = config_loader.create_index_params('test_shared_config')
# es.indices.create(**index_params)

# Build queries from shared templates  
wildcard_query = config_loader.build_query_from_template('wildcard_query', {
    'pattern': '*hawaii*'
})

regexp_query = config_loader.build_query_from_template('regexp_script_query', {
    'regex_pattern': r'hoo\w*\s+\w*\s+\w*\s+mai'
})

nested_query = config_loader.build_query_from_template('nested_sentence_wildcard', {
    'pattern': '*aloha*'
})

print("Python queries built successfully!")
print(f"Wildcard query: {wildcard_query}")
print(f"Nested query: {nested_query}")
