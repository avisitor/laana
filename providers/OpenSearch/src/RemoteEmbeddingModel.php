<?php

namespace HawaiianSearch;

/**
 * Registers the external embedding service (EMBEDDING_SERVICE_URL) as an ML
 * Commons remote model, so OpenSearch itself can embed query text in `neural`
 * queries. The Search Relevance Workbench needs this: its search
 * configurations substitute %SearchText% and cannot carry a precomputed vector.
 * No model runs inside OpenSearch — each call is an HTTP request to the
 * socket-activated embedding service.
 */
class RemoteEmbeddingModel
{
    public const MODEL_NAME = 'noiiolelo-e5-large-instruct';
    private const EMBED_MODEL = 'intfloat/multilingual-e5-large-instruct';

    // Turns the service's {"embeddings": [[...]]} into the tensor format neural-search expects.
    private const POST_PROCESS = <<<'PAINLESS'
def result = params.embeddings;
if (result == null || result.length == 0) { return "Embedding service returned no embeddings"; }
def out = new StringBuilder("[");
for (int m = 0; m < result.length; m++) {
  def e = new StringBuilder("[");
  for (int i = 0; i < result[m].length; i++) { e.append(result[m][i].floatValue()); if (i < result[m].length - 1) { e.append(","); } }
  e.append("]");
  out.append('{"name":"sentence_embedding","data_type":"FLOAT32","shape":[' + result[m].length + '],"data":' + e + '}');
  if (m < result.length - 1) { out.append(","); }
}
out.append("]");
return out.toString();
PAINLESS;

    private OpenSearchClient $client;
    private string $serviceUrl;

    public function __construct(OpenSearchClient $client, ?string $serviceUrl = null)
    {
        $this->client = $client;
        $this->serviceUrl = rtrim($serviceUrl ?? \Noiiolelo\EnvConfig::firstEnv('Embedding service URL', ['EMBEDDING_SERVICE_URL']), '/');
    }

    /** Model id of the deployed remote model, creating and deploying it if needed. */
    public function ensureDeployed(): string
    {
        $this->allowEndpoint();
        $modelId = $this->findModelId() ?? $this->register();
        $state = $this->request('GET', "/_plugins/_ml/models/$modelId")['model_state'] ?? '';
        if ($state !== 'DEPLOYED') {
            $this->waitForTask($this->request('POST', "/_plugins/_ml/models/$modelId/_deploy")['task_id']);
        }
        return $modelId;
    }

    /** Embed one text through the deployed model (proves the round trip). */
    public function embed(string $modelId, string $text): array
    {
        $res = $this->request('POST', "/_plugins/_ml/models/$modelId/_predict", [
            'parameters' => ['input' => [$text]],
        ]);
        return $res['inference_results'][0]['output'][0]['data'] ?? [];
    }

    /**
     * Single-node cluster: let ML tasks run on the data node and let
     * connectors reach exactly the embedding service's private endpoint.
     */
    private function allowEndpoint(): void
    {
        $pattern = '^' . preg_quote($this->serviceUrl, '/') . '/.*$';
        $key = 'plugins.ml_commons.trusted_connector_endpoints_regex';
        $settings = $this->request('GET', '/_cluster/settings?include_defaults=true&flat_settings=true');
        $trusted = $settings['persistent'][$key] ?? $settings['defaults'][$key] ?? [];
        if (!in_array($pattern, $trusted, true)) {
            $trusted[] = $pattern;   // keep the existing (default) trusted endpoints
        }
        $this->request('PUT', '/_cluster/settings', ['persistent' => [
            'plugins.ml_commons.only_run_on_ml_node' => false,
            'plugins.ml_commons.connector.private_ip_enabled' => true,
            'plugins.ml_commons.trusted_connector_private_endpoints_regex' => [$pattern],
            $key => $trusted,
        ]]);
    }

    private function findModelId(): ?string
    {
        $res = $this->request('POST', '/_plugins/_ml/models/_search', [
            'query' => ['term' => ['name.keyword' => self::MODEL_NAME]],
            'size' => 1,
        ]);
        return $res['hits']['hits'][0]['_id'] ?? null;
    }

    private function register(): string
    {
        $connector = $this->request('POST', '/_plugins/_ml/connectors/_create', [
            'name' => self::MODEL_NAME . '-connector',
            'description' => 'Noiiolelo embedding service (' . self::EMBED_MODEL . ', query prefix)',
            'version' => 1,
            'protocol' => 'http',
            // ML Commons rejects connectors without a credential; the local service needs none.
            'credential' => ['unused' => 'none'],
            'actions' => [[
                'action_type' => 'predict',
                'method' => 'POST',
                'url' => $this->serviceUrl . '/embed_sentences',
                'headers' => ['content-type' => 'application/json'],
                'request_body' => '{"sentences": ${parameters.input}, "prefix": "query: ", "model": "' . self::EMBED_MODEL . '"}',
                'pre_process_function' => 'connector.pre_process.default.embedding',
                'post_process_function' => self::POST_PROCESS,
            ]],
        ]);
        $task = $this->request('POST', '/_plugins/_ml/models/_register', [
            'name' => self::MODEL_NAME,
            'function_name' => 'remote',
            'description' => 'Query embeddings for neural/hybrid search on text_vector_1024',
            'connector_id' => $connector['connector_id'],
        ]);
        return $this->waitForTask($task['task_id'])['model_id'];
    }

    private function waitForTask(string $taskId): array
    {
        for ($i = 0; $i < 120; $i++) {
            $task = $this->request('GET', "/_plugins/_ml/tasks/$taskId");
            if (($task['state'] ?? '') === 'COMPLETED') {
                return $task;
            }
            if (in_array($task['state'] ?? '', ['FAILED', 'COMPLETED_WITH_ERROR'], true)) {
                throw new \RuntimeException("ML task $taskId failed: " . json_encode($task));
            }
            sleep(1);
        }
        throw new \RuntimeException("ML task $taskId did not complete in 120 s");
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        return $this->client->rawRequest($method, $path, $body);
    }
}
