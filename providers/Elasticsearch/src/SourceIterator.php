<?php

namespace HawaiianSearch;

use GuzzleHttp\Client as HttpClient;

class SourceIterator
{
    private $httpClient;
    private $sourceId;
    private $groupName;
    private $sources = [];
    private $position = 0;
    //private $url = "https://noiiolelo.org/api.php/sources?details";
    private $url = "https://noiiolelo.worldspot.org/api.php/sources?details&provider=MySQL";

    public function __construct($sourceId = null, $groupName = null)
    {
        $this->sourceId = $sourceId;
        $this->groupName = $groupName;
        echo "SourceIterator: sourceId = $sourceId, groupName = $groupName\n";
        $this->httpClient = new HttpClient();
        $this->fetchSources();
    }

    public function getSize() {
        return ($this->sources) ? count($this->sources) : 0;
    }
    
    private function fetchSources()
    {
        try {
            $resp = $this->httpClient->get($this->url);
            $data = json_decode($resp->getBody(), true);
            $allSources = $data['sources'] ?? [];

            if ($this->sourceId) {
                $this->sources = array_filter($allSources, function ($source) {
                    return isset($source['sourceid']) && $source['sourceid'] == $this->sourceId;
                });

                if (empty($this->sources)) {
                    echo "❌ Source ID {$this->sourceId} not found among " . count($allSources) . " available sources.\n";
                } else {
                    echo "✅ Found specific Source ID: {$this->sourceId}.\n";
                }
            } elseif ($this->groupName) {
                $this->sources = array_filter($allSources, function ($source) {
                    return isset($source['groupname']) && $source['groupname'] == $this->groupName;
                });

                if (empty($this->sources)) {
                    echo "❌ Source group {$this->groupName} not found among " . count($allSources) . " available sources.\n";
                } else {
                    echo "✅ Found " . count($this->sources) . " sources for {$this->groupName}.\n";
                }
            } else {
                $this->sources = $allSources;
                echo "✅ Fetched " . count($this->sources) . " sources.\n";
            }

            // Reindex to allow sequential iteration
            $this->sources = array_values($this->sources);

        } catch (Exception $e) {
            echo "❌ Failed to fetch sources: " . $e->getMessage() . "\n";
            $this->sources = [];
        }
    }

    public function getNext()
    {
        if ($this->position >= count($this->sources)) {
            return null;
        }

        return [$this->sources[$this->position++]];
    }
}

?>
