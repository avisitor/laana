import os
import sys
import array
import warnings
import requests
import argparse
import json
import time
from pprint import pprint
from elasticsearchclient import ElasticsearchDB

config = {
    "COLLECTION_NAME": "hawaiian_hybrid",
}

def fetch_sources():
    """
    Fetches source details from the API.
    """
    url = "https://noiiolelo.org/api.php/sources?details"
    resp = requests.get(url)
    resp.raise_for_status()
    data = resp.json()
    # The key is 'sources', which is a list of dictionaries (metadata)
    return data.get('sources', [])

def update_discarded(info, sourceid):
    if sourceid not in info["discarded_sourceids"]:
        info["discarded_sourceids"].add(sourceid)
    return info

def print_metadata(meta):
    print(f"Metadata:\n" +
          "  " + str(len(meta['processed_sourceids'])) + " processed\n" +
          "  " + str(len(meta['no_hawaiian_ids'])) + " no hawaiian\n" +
          "  " + str(len(meta['discarded_sourceids'])) + " discarded\n" +
          "  " + str(len(meta['english_only_ids'])) + " english only\n")
    print( f"Processed:\n{meta['processed_sourceids']}" )
    print( f"Discarded:\n{meta['discarded_sourceids']}" )

def update_sources():
    sources = fetch_sources() # Fetch source details
    print(f"Fetched {len(sources)} sources.")
    for src_idx, source in enumerate(sources): # Iterate through source details
        sourceid = str(source.get('sourceid')) # Extract and convert sourceid
        sourcename = source.get('sourcename', 'N/A') # Extract sourcename
        sentencecount = source.get('sentencecount', -1) # Extract sentencecount
        if sentencecount < 1:
            update_discarded(meta, sourceid)
    print_metadata( meta )
    #client.save_metadata( info )

def get_source_metadata( client ):
    resp = client.get_source_metadata()
    if resp is None:
        print(f"No existing metadata")
        meta = {
            "no_hawaiian_ids": set([]),
            "processed_sourceids": set([]),
            "discarded_sourceids": set([]),
            "english_only_ids": set([]),
        }
    else:
        #print(f"Fetched metadata: {resp['_source']}.")
        # Rewrap any list-like value as a set
        meta = {
            key: set(value) if isinstance(value, list) else value
            for key, value in resp['_source'].items()
        }
        #meta = resp['_source']
        print_metadata( meta )

def update_mapping( client ):
    client.client.indices.put_mapping(index=client.collection, body={
        "properties": {
            "sentences": {
                "type": "nested",
                "properties": {
                    "metadata": {
                        "type": "object",
                        "enabled": True
                    }
                }
            }
        }
    })

    response = client.client.indices.get_mapping(index=client.collection)
    #json.dumps(mapping, indent=4)
    pprint(response.body)

def inspect_metadata( client ):
    meta = client.fetch_metadata_hashes()
    return meta

def meta_size():
    total_size = 0
    for meta in metadata_list:
        total_size += sys.getsizeof(meta)
    print(f"Estimated memory usage: {total_size / (1024**2):.2f} MB")

def get_processed_sourceids( client ):
    sources = fetch_sources()
    discarded = set([])
    for src_idx, source in enumerate(sources): # Iterate through source details
        sourceid = str(source.get('sourceid')) # Extract and convert sourceid
        sentencecount = source.get('sentencecount', -1) # Extract sentencecount
        if sentencecount < 1:
            discarded.add(sourceid)

    processed = client.get_processed_sourceids()
    meta = {
        "no_hawaiian_ids": set([]),
        "processed_sourceids": processed,
        "discarded_sourceids": discarded,
        "english_only_ids": set([]),
    }
    pprint( meta )
    #client.save_source_metadata(meta)

def main(config):
    #parser = argparse.ArgumentParser(description="Process snapshot options.")
    #parser.add_argument('--recreate', action='store_true', help='If set, recreate from scratch')
    #args = parser.parse_args()
    #config["RECREATE_COLLECTION"] = args.recreate
    collection = config['COLLECTION_NAME'];
    
    client = ElasticsearchDB(config)

    #get_processed_sourceids( client )
    #return

    get_source_metadata( client )
    return

    count = client.fetch_metadata_count()
    print( f"{count} sentences in the metadata" )

    start_time = time.perf_counter()
    meta = inspect_metadata( client )
    print(f"Read metadata for {len(meta)} sentences")
    end_time = time.perf_counter()
    elapsed_time = end_time - start_time
    print(f"Elapsed time: {elapsed_time:.4f} seconds")
    #for doc in (client.get_all()):
    #    print(doc["_id"])
    

if __name__ == "__main__":
    # Call main function with defined variables
    main(config)

