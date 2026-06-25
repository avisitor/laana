<?php

namespace Noiiolelo;

interface GraphSearchProviderInterface
{
    /**
     * Extract entities from text using NER
     * @param string $text Text to process
     * @return array Entities found in text
     */
    public function extractEntities(string $text): array;

    /**
     * Extract relationships between entities in text
     * @param string $text Text to process
     * @return array Relationships found in text
     */
    public function extractRelationships(string $text): array;

    /**
     * Add entities to graph database
     * @param array $entities Entities to add
     * @return bool Success status
     */
    public function addEntities(array $entities): bool;

    /**
     * Add relationships to graph database
     * @param array $relationships Relationships to add
     * @return bool Success status
     */
    public function addRelationships(array $relationships): bool;

    /**
     * Execute graph query
     * @param string $query Cypher query
     * @param array $parameters Query parameters
     * @return array Query results
     */
    public function graphQuery(string $query, array $parameters = []): array;

    /**
     * Perform hybrid keyword and graph search
     * @param string $query Search query
     * @param array $graphFilters Graph-based filters
     * @return array Combined search results
     */
    public function hybridSearch(string $query, array $graphFilters = []): array;
}