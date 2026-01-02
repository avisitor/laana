<?php
/**
 * Backward compatibility wrapper for SaveManager.
 * New code should use Noiiolelo\Providers\MySQL\MySQLSaveManager directly
 * or the unified scripts/save.php.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Noiiolelo\Providers\MySQL\MySQLSaveManager;

class SaveManager extends MySQLSaveManager {
    public function __construct($options = []) {
        parent::__construct($options);
        $this->logName = "SaveManager (Legacy)";
    }
}
