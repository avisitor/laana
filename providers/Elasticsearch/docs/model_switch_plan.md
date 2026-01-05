# Implementation Plan: Switching to multilingual-e5-large-instruct

This document outlines the specific changes required to transition document embeddings from `intfloat/multilingual-e5-small` (384-dim) to `intfloat/multilingual-e5-large-instruct` (1024-dim), while retaining the small model for sentence embeddings.

## 1. Embedding Service (Python)
**File:** `/var/www/html/embedding_service/embed.py`

*   **Model Loading**: Update `ModelContainer` to load both models.
    ```python
    class ModelContainer:
        def load_models(self):
            self.models = {
                'intfloat/multilingual-e5-small': SentenceTransformer('intfloat/multilingual-e5-small', device=self.device),
                'intfloat/multilingual-e5-large-instruct': SentenceTransformer('intfloat/multilingual-e5-large-instruct', device=self.device)
            }
            self.nlp = spacy.load("en_core_web_sm", disable=["parser"])
    ```
*   **Request Schemas**: Add `model` field to `EmbedRequest` and `BatchEmbedRequest`.
    ```python
    class EmbedRequest(BaseModel):
        text: str
        prefix: str = "passage: "
        model: str = "intfloat/multilingual-e5-small"
    ```
*   **Endpoints**: Update `/embed` and `/embed_sentences` to use `models[request.model]`.

## 2. PHP Embedding Client
**File:** `/var/www/html/noiiolelo/providers/Elasticsearch/src/EmbeddingClient.php`

*   **Constants**: Add model name constants.
    ```php
    public const MODEL_SMALL = 'intfloat/multilingual-e5-small';
    public const MODEL_LARGE = 'intfloat/multilingual-e5-large-instruct';
    ```
*   **Method Signatures**: Update `embedText` and `embedSentences` to accept `$modelName`.
    ```php
    public function embedText(string $text, string $prefix = 'query: ', string $modelName = self::MODEL_SMALL): ?array
    ```
*   **Payload**: Include `'model' => $modelName` in the JSON payload sent to the Python service.

## 3. Elasticsearch Client Configuration
**File:** `/var/www/html/noiiolelo/providers/Elasticsearch/src/ElasticsearchClient.php`

*   **MODEL_CONFIG**: Add the large model configuration.
    ```php
    'intfloat/multilingual-e5-large-instruct' => [
        'dims' => 1024,
        'query_prefix' => 'query: ',
        'passage_prefix' => 'passage: '
    ]
    ```
*   **Validation**: Update `validateVectorDimensions` to check `text_vector_1024` against 1024 dimensions if present in the action.

## 4. Index Mapping
**File:** `/var/www/html/noiiolelo/providers/Elasticsearch/config/documents_mapping.json`

*   **New Field**: Add `text_vector_1024` to the `properties` object.
    ```json
    "text_vector_1024": {
      "type": "dense_vector",
      "dims": 1024,
      "index": true,
      "similarity": "cosine"
    }
    ```

## 5. Corpus Indexer Logic
**File:** `/var/www/html/noiiolelo/providers/Elasticsearch/src/CorpusIndexer.php`

*   **Document Embedding**: In `processSource`, change the `embedText` call to use the large model.
    ```php
    $textVector1024 = $this->retryEmbeddingOperation(function() use ($embeddingClient, $text) {
        return $embeddingClient->embedText($text, 'passage: ', EmbeddingClient::MODEL_LARGE);
    }, "embedText", "text (large)");
    ```
*   **Document Assembly**: Update the `$doc` array to include `text_vector_1024` and stop assigning to `text_vector`.
    ```php
    "text_vector_1024" => $textVector1024,
    // "text_vector" => $textVector, // No longer maintaining this
    ```
*   **Sentence Embedding**: Ensure `processSentencesInBatches` explicitly passes `EmbeddingClient::MODEL_SMALL` (or relies on the new default).

## 6. Query Builder (Evaluation Support)
**File:** `/var/www/html/noiiolelo/providers/Elasticsearch/src/QueryBuilder.php`

*   **Dynamic Field Selection**: Update `vectorQuery`, `hybridQuery`, and `knnQuery` to select the field and model based on an option.
    ```php
    $useLarge = $options['use_large_model'] ?? true; // Default to large now
    $field = $useLarge ? 'text_vector_1024' : 'text_vector';
    $model = $useLarge ? EmbeddingClient::MODEL_LARGE : EmbeddingClient::MODEL_SMALL;
    
    $vector = $this->embeddingClient->embedText($text, 'query: ', $model);
    ```

## 7. Maintenance Actions
1.  **Update Mapping**: Run `PUT /hawaiian_documents_new/_mapping` with the new field definition.
2.  **Restart Service**: `systemctl restart embedding.service`.
3.  **Backfill Script**: Create `scripts/backfill_large_embeddings.php` to:
    *   Scroll through all documents.
    *   Generate 1024-dim embeddings.
    *   Bulk update `text_vector_1024` for existing records.
