# Neo4j Provider for Noiiolelo

This provider integrates Neo4j graph database capabilities with the existing Noiiolelo search infrastructure, enabling hybrid keyword and graph queries.

## Features

- **Named Entity Recognition (NER)**: Identifies and extracts entities from text
- **Relationship Extraction**: Detects relationships between entities 
- **Graph Storage**: Stores entities and relationships in Neo4j
- **Hybrid Queries**: Combines keyword searches with graph-based filtering
- **Integration**: Works alongside existing Elasticsearch and OpenSearch providers

## Usage

```php
// Initialize Neo4j provider
$provider = ProviderFactory::create('neo4j', [
    'uri' => 'bolt://localhost:7687',
    'username' => 'neo4j',
    'password' => 'password'
]);

// Extract entities from text
$text = "Kamehameha founded Lahainaluna School.";
$entities = $provider->extractEntities($text);
$relationships = $provider->extractRelationships($text);

// Store in Neo4j database
$provider->addEntities($entities);
$provider->addRelationships($relationships);

// Perform hybrid search (keyword + graph)
$results = $provider->hybridSearch("Kamehameha", ['type' => 'Person']);
```

## Entity and Relationship Extraction Model

The extraction follows this model:

```json
{
  "entities": [
    {"name": "Kamehameha", "type": "Person", "id": "KAMEHAMEHA_I"},
    {"name": "Lahainaluna", "type": "Location", "id": "LAHAINALUNA_SCHOOL"}
  ],
  "relationships": [
    {"source": "KAMEHAMEHA_I", "relation": "FOUNDED", "target": "LAHAINALUNA_SCHOOL"}
  ]
}
```

## Integration with Existing Providers

The Neo4j provider implements the `GraphSearchProviderInterface` which extends the existing `SearchProviderInterface`, ensuring that:

- All existing search providers (Elasticsearch, OpenSearch, MySQL, Postgres) maintain backward compatibility
- NER and relationship extraction can be done using the Neo4j provider
- Hybrid search combines keyword and graph search capabilities

## Automated Entity/Relationship Database Maintenance

Entities and relationships can be automatically extracted and maintained during document ingestion:

### Processing Existing Documents
```bash
# Backfill existing documents with entity/relationship data
php scripts/backfill_entities.php
```

### Processing New Documents
```bash
# Process a new document as it's ingested
php scripts/process_new_documents.php <document_id>
```

## Database Maintenance

The system supports:
1. **Backfilling existing documents** to populate the graph database
2. **Automatic processing** of new documents during ingestion
3. **Status monitoring** of the entity database
4. **Hybrid search queries** combining keyword and graph-based results

## Example Use Cases

1. **Historical Research**: Find all documents mentioning "Kamehameha" and then explore his relationships with locations, institutions, and people
2. **Cultural Mapping**: Identify connections between Hawaiian cultural sites, figures, and events
3. **Knowledge Graph**: Build a comprehensive knowledge graph of Hawaiian history and culture
4. **Semantic Search**: Enhance keyword search with graph-based understanding of entity relationships