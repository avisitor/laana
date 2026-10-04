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

Entities come from the curated name lists in `data/name_lists`, restricted to
the lists named in `data/entity_sources` (see `NameListEntityExtractor`).
Matching is diacritic-insensitive (okina and macrons are flattened on both
the list keys and the text tokens, hyphens read as spaces), alias matches
resolve to the record's core entity, and precision gates reject lowercase
usage, sentence-start particles, calendar words, and SSA-only given names
that lack a capitalized surname run. The legacy `AdvancedEntityExtractor`
(regex over capitalized words) is retained only for the old stop-list
workflow and is no longer used by the rebuild or backfill scripts.

```json
{
  "entities": [
    {"name": "Kamehameha III", "type": "Person", "id": "PERSON_...",
     "source": "historical_figures.json", "category": "Monarch",
     "birth_year": 1813, "death_year": 1854},
    {"name": "Lahainaluna", "type": "Place", "id": "PLACE_..."}
  ],
  "relationships": [
    {"source": "PERSON_...", "relation": "CO_OCCURS_WITH", "target": "PLACE_..."}
  ]
}
```

Graph labels: `Person` (given names, SSA names, historical and literary
figures), `Place` (GNIS and Hawaiian place names), `Wind`, `Rain`,
`Rainbow`, `Weapon`. Relationships: sentence-level `CO_OCCURS_WITH` plus
document-level `MENTIONED_IN` and `CO_MENTIONED_WITH` (added by the rebuild
and backfill scripts).

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