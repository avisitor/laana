<?php

namespace HawaiianSearch;

class PostgresSourceReader
{
    /**
     * Parse a Postgres pgvector text representation into a PHP float array.
     *
     * Handles the format: '[0.1, 0.2, 0.3]'
     *
     * @param string|null $pg Raw vector string from Postgres
     * @return array<int, float> Parsed float values
     */
    public static function pgvectorToArray(string|null $pg): array
    {
        if ($pg === null || $pg === '' || $pg === '[]') {
            return [];
        }

        // Strip surrounding brackets
        $inner = trim($pg, '[]');

        if ($inner === '') {
            return [];
        }

        $parts = explode(',', $inner);
        $result = [];

        foreach ($parts as $part) {
            $result[] = (float) trim($part);
        }

        return $result;
    }
}
