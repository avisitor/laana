<?php
namespace Noiiolelo\Providers\Neo4j;

use Noiiolelo\AbstractSearchProvider;
use Noiiolelo\GraphSearchProviderInterface;

class Neo4jProvider extends AbstractSearchProvider implements GraphSearchProviderInterface
{
    protected $database = 'neo4j';
    protected $uri = 'http://localhost:7474';
    protected $username = 'neo4j';
    protected $password = 'password';
    private bool $schemaEnsured = false;
    
    public function __construct(array $options = [])
    {
        // Load .env so getenv() sees the project configuration (entry points
        // like auto_classify_entities.php never load it themselves).
        if (class_exists('Avisitor\\Env\\Loader')) {
            \Avisitor\Env\Loader::load(__DIR__ . '/../../.env');
        }

        // Initialize from options or environment variables, failing loudly when
        // the graph connection is unconfigured instead of silently using
        // hardcoded localhost defaults.
        if (isset($options['uri'])) {
            $this->uri = $options['uri'];
            // Convert bolt:// to http:// for HTTP API
            $this->uri = str_replace('bolt://', 'http://', $this->uri);
            $this->uri = str_replace(':7687', ':7474', $this->uri);
        } else {
            $this->uri = \Noiiolelo\EnvConfig::firstEnv('Neo4j URI', ['NEO4J_URI']);
        }

        $this->username = $options['username'] ?? \Noiiolelo\EnvConfig::firstEnv('Neo4j username', ['NEO4J_USERNAME']);
        $this->password = $options['password'] ?? \Noiiolelo\EnvConfig::firstEnv('Neo4j password', ['NEO4J_PASSWORD']);
    }

    public function getName(): string
    {
        return "Neo4j";
    }

    private function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $this->executeCypher('CREATE CONSTRAINT graph_entity_id IF NOT EXISTS FOR (n:GraphEntity) REQUIRE n.id IS UNIQUE');
        $this->executeCypher('CREATE CONSTRAINT document_id IF NOT EXISTS FOR (d:Document) REQUIRE d.id IS UNIQUE');
        $this->executeCypher('MATCH (n) WHERE n.id IS NOT NULL SET n:GraphEntity');
        $this->schemaEnsured = true;
    }
    
    /**
     * Execute Cypher query via HTTP API
     */
    private function executeCypher(string $query, array $parameters = []): ?array
    {
        try {
            $url = $this->uri . '/db/neo4j/tx/commit';
            $paramsPayload = empty($parameters) ? new \stdClass() : $parameters;
            
            $payload = [
                'statements' => [
                    [
                        'statement' => $query,
                        'parameters' => $paramsPayload
                    ]
                ]
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false || !empty($error)) {
                return null;
            }
            
            if ($httpCode !== 200 && $httpCode !== 201) {
                // Neo4j not available or query failed - return empty result gracefully
                return null;
            }
            
            $data = json_decode($response, true);
            if (!$data || isset($data['errors']) && !empty($data['errors'])) {
                return null;
            }
            
            if (isset($data['results'][0]['data'])) {
                $results = [];
                foreach ($data['results'][0]['data'] as $row) {
                    $results[] = $row['row'][0] ?? $row;
                }
                return $results;
            }
            
            return [];
        } catch (\Exception $e) {
            // Silently fail if Neo4j is not running
            return null;
        }
    }
    
    public function getRawClient()
    {
        return null;
    }

    public function extractEntities(string $text): array
    {
        return AdvancedEntityExtractor::extractEntities($text);
    }

    public function extractRelationships(string $text): array
    {
        $entities = $this->extractEntities($text);
        return AdvancedEntityExtractor::extractRelationships($text, $entities);
    }

    public function addEntities(array $entities): bool
    {
        if (empty($entities)) {
            return true;
        }

        $this->ensureSchema();

        $groupedEntities = [];
        foreach ($entities as $entity) {
            $label = preg_replace('/[^A-Za-z0-9_]/', '', (string)($entity['type'] ?? 'Entity'));
            if (empty($label)) {
                $label = 'Entity';
            }

            $groupedEntities[$label][] = [
                'id' => $entity['id'] ?? md5(($entity['name'] ?? '') . ($entity['type'] ?? '')),
                'name' => $entity['name'] ?? '',
            ];
        }

        foreach ($groupedEntities as $label => $rows) {
            $query = "UNWIND \$rows AS row MERGE (n:GraphEntity {id: row.id}) SET n.name = row.name SET n:$label RETURN count(n)";
            $this->executeCypher($query, ['rows' => $rows]);
        }

        return true;
    }

    public function addRelationships(array $relationships): bool
    {
        if (empty($relationships)) {
            return true;
        }

        $this->ensureSchema();

        $groupedRelationships = [];
        foreach ($relationships as $rel) {
            $relationType = preg_replace('/[^A-Za-z0-9_]/', '', (string)($rel['relation'] ?? 'RELATED_TO'));
            if (empty($relationType)) {
                $relationType = 'RELATED_TO';
            }

            $source = (string)($rel['source'] ?? '');
            $target = (string)($rel['target'] ?? '');
            if ($source === '' || $target === '') {
                continue;
            }

            $groupedRelationships[$relationType][] = [
                'source' => $source,
                'target' => $target,
            ];
        }

        foreach ($groupedRelationships as $relationType => $rows) {
            if ($relationType === 'CO_MENTIONED_WITH') {
                $query = "UNWIND \$rows AS row MATCH (s:GraphEntity {id: row.source}), (t:GraphEntity {id: row.target}) "
                       . "MERGE (s)-[rel:$relationType]->(t) "
                       . "ON CREATE SET rel.weight = 1 "
                       . "ON MATCH SET rel.weight = coalesce(rel.weight, 0) + 1 RETURN count(*)";
            } else {
                $query = "UNWIND \$rows AS row MATCH (s:GraphEntity {id: row.source}), (t:GraphEntity {id: row.target}) "
                       . "MERGE (s)-[:$relationType]->(t) RETURN count(*)";
            }
            $this->executeCypher($query, ['rows' => $rows]);
        }

        return true;
    }

    public function graphQuery(string $query, array $parameters = []): array
    {
        $result = $this->executeCypher($query, $parameters);
        return $result ?? [];
    }

    public function hybridSearch(string $query, array $graphFilters = []): array
    {
        return [
            'keyword_results' => ['hits' => [], 'total' => 0],
            'graph_results' => [],
            'total' => 0
        ];
    }

    public function __destruct()
    {
    }
}
