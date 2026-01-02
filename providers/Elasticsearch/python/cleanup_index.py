import argparse
from elasticsearchclient import ElasticsearchDB

def cleanup_missing_field(config, field_name, dryrun=False):
    """
    Deletes documents from an Elasticsearch index that are missing a specific field.
    """
    # The client needs to be initialized without the RECREATE_COLLECTION flag
    # to avoid accidentally deleting the index.
    db_config = config.copy()
    db_config["RECREATE_COLLECTION"] = False
    client = ElasticsearchDB(db_config)
    
    index_name = client.collection

    query = {
        "query": {
            "bool": {
                "must_not": [
                    {
                        "exists": {
                            "field": field_name
                        }
                    }
                ]
            }
        }
    }

    if dryrun:
        print(f"DRY RUN: Would search for documents in index '{index_name}' missing the field '{field_name}'.")
        # Use count API to show how many would be deleted
        try:
            response = client.client.count(index=index_name, body=query)
            count = response.get('count', 0)
            print(f"DRY RUN: Found {count} documents that would be deleted.")
        except Exception as e:
            print(f"❌ An error occurred during count: {e}")
        return

    print(f"Searching for and deleting documents in index '{index_name}' missing the field '{field_name}'...")

    try:
        response = client.client.delete_by_query(
            index=index_name,
            body=query,
            wait_for_completion=True,
            refresh=True # Refresh the index to make changes visible immediately
        )
        deleted_count = response.get('deleted', 0)
        print(f"✅ Successfully deleted {deleted_count} documents.")
        if response.get('failures'):
            print("⚠️ Encountered failures during deletion:")
            for failure in response['failures']:
                print(f"  - {failure}")
    except Exception as e:
        print(f"❌ An error occurred: {e}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Clean up Elasticsearch index by deleting documents missing a specific field.")
    parser.add_argument(
        "--field",
        type=str,
        default="hawaiian_word_ratio",
        help="The name of the field to check for existence."
    )
    parser.add_argument(
        "--dryrun",
        action="store_true",
        help="Perform a dry run without deleting any documents."
    )
    args = parser.parse_args()

    config = {
        "COLLECTION_NAME": "hawaiian_hybrid"
    }

    cleanup_missing_field(config, args.field, args.dryrun)
