<?php
/**
 * Simple .env file loader for database configuration
 */

function loadEnv($envFile = null): array {
    $env = [];
    // Search for .env file in multiple locations if not specified
    if ($envFile === null) {
        $searchPaths = [
            __DIR__ . '/.env',
            __DIR__ . '/../.env', 
            __DIR__ . '/../../.env'
        ];
        
        $envFile = null;
        foreach ($searchPaths as $path) {
            if (file_exists($path)) {
                $envFile = $path;
                break;
            }
        }
        
        if ($envFile === null) {
            error_log("No .env file found in any of the search paths");
            return $env;
        }
    }
    
    if (!file_exists($envFile)) {
        error_log("No .env file $envFile");
        return $env;
    }
    
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    //echo "loadEnv: " . var_export( $lines, true ) . "\n";
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse key=value pairs
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            
            $_ENV[$key] = $value;
            $env[$key] = $value;
            putenv("$key=$value");
        }
    }
    //echo "loadEnv set _ENV: " . var_export( $_ENV, true ) . "\n";
    
    return $env;
}

// Load environment variables
loadEnv();

?>
