def save_discarded_sourceids(info):
    """Saves a set of discarded source IDs to a JSON file."""
    try:
        info["updated_discarded_ids"] = info["discarded_sourceids"].union(info["newly_discarded_ids"])
        discarded_ids = info["updated_discarded_ids"]
        filepath = info["discarded_ids_file"]        # Ensure the directory exists
        os.makedirs(os.path.dirname(filepath), exist_ok=True)
        with open(filepath, 'w') as f:
            json.dump(list(discarded_ids), f)
        print(f"Saved {len(discarded_ids)} discarded source IDs to {filepath}")
    except Exception as e:
        print(f"Error saving discarded source IDs to {filepath}: {e}")

def load_discarded_sourceids(filepath):
    """Loads a set of discarded source IDs from a JSON file."""
    discarded_ids = set()
    if os.path.exists(filepath):
        try:
            with open(filepath, 'r') as f:
                loaded_ids = json.load(f)
                # Ensure loaded data is a list before converting to set
                if isinstance(loaded_ids, list):
                    discarded_ids = set(loaded_ids)
                    print(f"Loaded {len(discarded_ids)} discarded source IDs from {filepath}")
                else:
                    print(f"Warning: Data in {filepath} is not a list. Starting with empty discarded IDs.")
        except json.JSONDecodeError:
            print(f"Error decoding JSON from {filepath}. File might be corrupt. Starting with empty discarded IDs.")
        except Exception as e:
            print(f"Error loading discarded source IDs from {filepath}: {e}")
    else:
        print(f"Discarded source IDs file not found at {filepath}. Starting with an empty list.")
    return discarded_ids

def save_english_only_sourceids(info):
    """Saves a set of source IDs containing only English text to a JSON file."""
    try:
        info["updated_english_only_ids"] = info["english_only_ids"].union(info["newly_english_only_ids"])
        # Ensure the directory exists
        filepath = info["english_only_ids_file"]
        ids = info["english_only_ids"]
        os.makedirs(os.path.dirname(filepath), exist_ok=True)
        with open(filepath, 'w') as f:
            json.dump(list(ids), f)
        print(f"Saved {len(ids)} English-only source IDs to {filepath}")
    except Exception as e:
        print(f"Error saving English-only source IDs to {filepath}: {e}")

