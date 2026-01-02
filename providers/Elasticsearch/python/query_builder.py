
from config_loader import config_loader

class QueryBuilder:
    MODES = ["match", "term", "phrase", "regexp", "wildcard", "vector", "hybrid", "vectorsentence", "hybridsentence", "knn", "matchsentence_all"]

    def __init__(self, embedder):
        self.embed_text = embedder  # Function that returns a vector from query_text
        self.handlers = {
            "match": self.match_query,
            "term": self.term_query,
            "phrase": self.phrase_query,
            "regexp": self.regexp_query,
            "wildcard": self.wildcard_query,
            "vector": self.vector_query,
            "hybrid": self.hybrid_query,
            "vectorsentence": self.sentence_vector_query,
            "hybridsentence": self.hybrid_sentence_query,
            "knn": self.knn_query,
            "matchsentence_all": self.matchsentence_all_query,
        }

    
    def build_from_template(self, template_name, variables=None):
        """Build query from shared template with variable substitution."""
        if variables is None:
            variables = {}
        return config_loader.build_query_from_template(template_name, variables)
    
    def get_wildcard_query(self, pattern, field='text.wildcard'):
        """Build wildcard query using shared template."""
        return self.build_from_template('wildcard_query', {'pattern': pattern})
    
    def get_regexp_query(self, regex_pattern, field='text.raw'):
        """Build regex query using shared template.""" 
        return self.build_from_template('regexp_script_query', {'regex_pattern': regex_pattern})
    
    def get_nested_sentence_wildcard(self, pattern):
        """Build nested sentence wildcard query."""
        return self.build_from_template('nested_sentence_wildcard', {'pattern': pattern})
    
    def get_hybrid_query(self, query_text, embedding_vector, text_boost=1.0, vector_boost=1.0):
        """Build hybrid query using shared template."""
        return self.build_from_template('hybrid_query', {
            'query_text': query_text,
            'embedding_vector': embedding_vector,
            'text_boost': text_boost,
            'vector_boost': vector_boost
        })

    def _add_ratio_boost(self, query):
        return {
            "function_score": {
                "query": query,
                "functions": [
                    {
                        "field_value_factor": {
                            "field": "hawaiian_word_ratio",
                            "modifier": "ln1p",
                            "factor": 1.0,
                            "missing": 1
                        }
                    }
                ],
                "boost_mode": "multiply"
            }
        }

    def build(self, mode, query_text, **kwargs):
        handler = self.handlers.get(mode, self.match_query)
        query = handler(query_text, **kwargs)
        return query if "query" in query or "knn" in query else { "query": query }

    def match_query(self, text, **kwargs):
        base_query = { "match": { "text": text } }
        return self._add_ratio_boost(base_query)

    def term_query(self, text, **kwargs):
        base_query = { "term": { "text.keyword": text } }
        return self._add_ratio_boost(base_query)

    def phrase_query(self, text, **kwargs):
        base_query = { "match_phrase": { "text": text } }
        return self._add_ratio_boost(base_query)

    def regexp_query(self, text, **kwargs):
        base_query = { "regexp": { "text.keyword": { "value": text } } }
        return self._add_ratio_boost(base_query)

    def wildcard_query(self, text, **kwargs):
        base_query = {
            "wildcard": {
                "text": {
                    "value": text,
                    "case_insensitive": True
                }
            }
        }
        return self._add_ratio_boost(base_query)

    def vector_query(self, text, **kwargs):
        query_vector = self.embed_text(text)
        return {
            "script_score": {
                "query": {
                    "bool": {
                        "filter": [
                            { "exists": { "field": "text_vector" } },
                            { "exists": { "field": "hawaiian_word_ratio" } }
                        ]
                    }
                },
                "script": {
                    "source": "(cosineSimilarity(params.query_vector, 'text_vector') + 1.0) * (doc['hawaiian_word_ratio'].value + 0.1)",
                    "params": {
                        "query_vector": query_vector
                    }
                }
            }
        }

    def sentence_vector_query(self, text, **kwargs):
        k = kwargs.get('k', 100)
        num_candidates = k * 2
        query_vector = self.embed_text(text)
        return {
            "query": {
                "nested": {
                    "path": "sentences",
                    "query": {
                        "knn": {
                            "field": "sentences.vector",
                            "query_vector": query_vector,
                            "k": k,
                            "num_candidates": num_candidates
                        }
                    },
                    "inner_hits": {
                        "name": "matched_sentences",
                        "size": k,
                        "_source": True
                    }
                }
            },
            "_source": ["sourcename"]
        }

    def hybrid_query(self, text, **kwargs):
        query_vector = self.embed_text(text)
        return {
            "script_score": {
                "query": {
                    "bool": {
                        "should": [
                            { "match": { "text": text } },
                            {
                                "wildcard": {
                                    "text": {
                                        "value": f"*{text}*",
                                        "case_insensitive": True
                                    }
                                }
                            }
                        ],
                        "filter": [
                            { "exists": { "field": "text" } },
                            { "exists": { "field": "text_vector" } },
                            { "exists": { "field": "hawaiian_word_ratio" } },
                            {
                                "script": {
                                    "script": {
                                        "source": "doc['text.keyword'].size() != 0 && doc['text.keyword'].value != ''",
                                        "lang": "painless"
                                    }
                                }
                            }
                        ]
                    }
                },
                "script": {
                    "source": "(cosineSimilarity(params.query_vector, 'text_vector') + 1.0) * (doc['hawaiian_word_ratio'].value + 0.1)",
                    "params": {
                        "query_vector": query_vector
                    }
                }
            }
        }

    def hybrid_sentence_query(self, query_text, **kwargs):
        query_vector = self.embed_text(query_text)

        return {
            "query": {
                "nested": {
                    "path": "sentences",
                    "score_mode": "max",
                    "query": {
                        "function_score": {
                            "query": {
                                "match": {
                                    "sentences.text": {
                                        "query": query_text,
                                        "operator": "and"
                                    }
                                }
                            },
                            "functions": [
                                {
                                    "script_score": {
                                        "script": {
                                            "source": "cosineSimilarity(params.query_vector, 'sentences.vector') + 1.0",
                                            "params": {
                                                "query_vector": query_vector
                                            }
                                        }
                                    }
                                }
                            ],
                            "boost_mode": "multiply"
                        }
                    },
                    "inner_hits": {
                        "name": "matched_sentences",
                        "size": 5,
                        "sort": [
                            {
                                "_score": {
                                    "order": "desc"
                                }
                            }
                        ],
                        "_source": True
                    }
                }
            },
            "_source": ["sourcename", "text"]
        }

    def vector_score_query(self, query_text):
        query_vector = self.embed_text(query_text)

        return {
            "query": {
                "script_score": {
                    "query": {
                        "match_all": {}  # Score every document
                    },
                    "script": {
                        "source": """
                            if (!doc.containsKey('text_vector') || doc['text_vector'].size() == 0) {
                                return 0.0;
                            }
                            return cosineSimilarity(params.query_vector, doc['text_vector']) + 1.0;
                        """,
                        "params": {
                            "query_vector": query_vector
                        }
                    }
                }
            },
            "_source": ["sourcename", "text"]
        }

    def sentence_match_query(self, query_text):
        highlight_field = "text"

        return {
            "query": {
                "nested": {
                    "path": "sentences",
                    "query": {
                        "match": {
                            "sentences.text": query_text
                        }
                    },
                    "score_mode": "none",  # Don't interfere with root scoring
                    "inner_hits": {
                        "name": "matched_sentences",
                        "highlight": {
                            "fields": {
                                "sentences.text": {
                                    "fragment_size": 150,
                                    "number_of_fragments": 3
                                }
                            }
                        },
                        "_source": [
                            "sentences.text",
                            "sentences.position"
                        ]
                    }
                }
            },
            "_source": ["sourcename", highlight_field]
        }

    def knn_query(self, text):
        query_vector = self.embed_text(text)
        return {
            "knn": {
                "field": "text_vector",
                "query_vector": query_vector,
                "k": 10,
                "num_candidates": 100,
                "filter": {
                    "bool": {
                        "filter": [
                            {
                                "exists": {
                                    "field": "text"
                                }
                             },
                            #{
                            #    "script": {
                            #        "script": {
                            #            "source": "doc['text.keyword'].size() != 0 && doc['text.keyword'].value != ''",
                            #            "lang": "painless"
                            #        }
                            #    }
                            #}
                        ]
                    }
                }
            }
        }

    def matchsentence_all_query(self, query_text, **kwargs):
        return {
            "query": {
                "nested": {
                    "path": "sentences",
                    "query": {
                        "match": {
                            "sentences.text": {
                                "query": query_text,
                                "operator": "and"
                            }
                        }
                    },
                    "inner_hits": {
                        "name": "matched_sentences",
                        "_source": True
                    }
                }
            },
            "_source": ["sourcename", "text"]
        }
