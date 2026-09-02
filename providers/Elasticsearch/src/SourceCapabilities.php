<?php

namespace HawaiianSearch;

class SourceCapabilities
{
    public bool $sentenceVectors = false;    // provider supplies per-sentence 384-dim vectors
    public bool $documentVector384 = false;  // provider supplies legacy full-document 384-dim vector
    public bool $documentVector1024 = false; // provider supplies full-document 1024-dim vector (text_vector_1024)
    public bool $rawHtml = false;            // provider can supply raw HTML (for --import-raw)
    public bool $sentenceBoilerplateScore = false; // provider supplies per-sentence boilerplate_score
                                                   // (when false, the indexer computes it via MetadataExtractor)

    public function hasAnyVector(): bool
    {
        return $this->sentenceVectors || $this->documentVector384 || $this->documentVector1024;
    }
}
