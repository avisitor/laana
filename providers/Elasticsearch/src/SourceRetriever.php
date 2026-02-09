<?php
namespace HawaiianSearch;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

   // Retrieves sources from the Laana database or Elasticsearch
   class SourceRetriever {
        private $sourceIndex;
        private $sources = [];
        private $config = [];
        private $httpClient;
        private $client;
        //private $baseurl = "https://noiiolelo.org/api.php/source/";
        private $baseurl = "https://noiiolelo.worldspot.org/api.php/source/";
        private $sourcesURL = "https://noiiolelo.worldspot.org/api.php/sources?details&provider=MySQL";
 
        public function __construct($options) {
             $this->httpClient = $options['httpClient'] ?? null;
             $this->client = $options['client'] ?? null;
             $this->config = $options;
         }

        protected function print( $msg ) {
            if (!isset($this->config["quiet"]) || !$this->config["quiet"]) {
                error_log("SourceRetriever: $msg");
            }
        }
        
        public function fetchSource(string $sourceid, string $type): ?string
        {
            $key = ($type == 'plain') ? 'text' : $type;
            
            $url = "{$this->baseurl}{$sourceid}/$type?provider=MySQL";
            $this->print("Fetching source $sourceid of type $type from $url...");
            try {
                $resp = $this->httpClient->get($url);
                $data = json_decode($resp->getBody(), true);
                $result = $data[$key] ?? null;
            } catch (ClientException $e) {
                $result = null;
                $this->print ("Failure fetching $url: ClientException" );
            } catch (RequestException $e) {
                $result = null;
                $this->print ("Failure fetching $url: RequestException" );
            } catch (\Exception $e) {
                $result = null;
                $this->print ("Failure fetching $url: Exception" );
            }
            return $result;
        }

        public function fetchSources( $options = [] ): array {
             $sourceIndex = $options['sourceIndex'] ?? null;
             $sourceId = $options['sourceId'] ?? null;
             $groupName = $options['groupName'] ?? null;
            
            if ($sourceIndex && $this->client) {
                // Fetch source IDs from the existing Elasticsearch index
                $this->print("Fetching document IDs from source index: {$sourceIndex}...");
                $this->sources = $this->client->getAllSourceIds($sourceIndex);
                $this->print("✅ Found "  . count($this->sources) . " documents in source index {$sourceIndex}.\n");
            } else if( $this->httpClient ) {
                // Always fetch all sources first
                try {
                    $resp = $this->httpClient->get($this->sourcesURL);
                    $data = json_decode($resp->getBody(), true);
                    $allSources = isset($data['sources']) ? $data['sources'] : [];
                    
                    if ($sourceId) {
                        // Filter to only the specified source ID
                        $this->sources = array_filter($allSources, function($source) use ($sourceId) {
                            return isset($source['sourceid']) && $source['sourceid'] == $sourceId;
                        });
                        
                        if (empty($this->sources)) {
                            $this->print("❌ Source ID {$sourceId} not found among " . count($allSources) . " available sources.");
                        } else {
                            $this->print("✅ Found specific Source ID: {$sourceId}.");
                        }
                    } else {
                        // Use all sources
                        $this->sources = $allSources;
                        echo "✅ Fetched " . count($this->sources) . " sources.\n";
                    }
                } catch (\Exception $e) {
                    $this->print("❌ Failed to fetch sources: " . $e->getMessage() );
                    $this->sources = [];
                }
            } else {
                $this->sources = [];
                $msg = "❌ Failed to fetch sources: ";
                if( !$this->httpClient ) {
                    $msg .= "No HTTP client available.";
                }
                if( !$this->client ) {
                    $msg .= " No Elasticsearch client available.";
                }
                $this->print($msg );
            }
            return $this->sources;
        }
    }
