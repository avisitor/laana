from collections import OrderedDict
import time

class MetadataCache:
    def __init__(self, es_client, max_size=10000, flush_interval=60):
        self.es = es_client
        self.cache = OrderedDict()
        self.dirty = set()  # hashes with modified values
        self.max_size = max_size
        self.last_flush = time.time()
        self.flush_interval = flush_interval  # in seconds

    def get(self, h):
        if h in self.cache:
            self.cache.move_to_end(h)
            return self.cache[h]

        meta = self.es.get_metadata(h) or {}
        self.cache[h] = meta
        return meta

    def set(self, h, value):
        self.cache[h] = value
        self.dirty.add(h)
        self.cache.move_to_end(h)

        if len(self.cache) > self.max_size:
            self.evict()

        now = time.time()
        if now - self.last_flush >= self.flush_interval:
            self.flush()

    def evict(self):
        old_h, old_val = self.cache.popitem(last=False)
        if old_h in self.dirty:
            self.es.update_metadata({old_h: old_val})
            self.dirty.remove(old_h)

    def flush(self):
        updates = {h: self.cache[h] for h in self.dirty}
        if updates:
            self.es.update_metadata(updates)
            self.dirty.clear()
            self.last_flush = time.time()

    def finish(self):
        self.flush()
