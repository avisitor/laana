<?php
namespace Noiiolelo;

require_once __DIR__ . '/../db/PostgresFuncs.php';
require_once __DIR__ . '/SearchProviderInterface.php';
require_once __DIR__ . '/LaanaSearchProvider.php';

class PostgresSearchProvider extends LaanaSearchProvider implements SearchProviderInterface
{
    public function __construct($options) {
        // Replace underlying Laana with PostgresLaana
        $this->laana = new \PostgresLaana();
        $this->pageSize = $this->laana->pageSize;
    }

    public function getName(): string {
        return 'Postgres';
    }

    // Explicitly declare available modes to ensure UI population
    public function getAvailableSearchModes(): array
    {
        return [
            'exact' => 'Match exact phrase',
            'any' => 'Match any of the words',
            'all' => 'Match all words in any order',
            'near' => 'Words adjacent in order',
            'regex' => 'Regular expression search',
            'hybrid' => 'Hybrid: keyword + vector + quality',
        ];
    }
}

?>
