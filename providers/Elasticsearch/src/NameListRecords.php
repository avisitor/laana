<?php

namespace HawaiianSearch;

/**
 * Canonical record schema shared by every name list in data/name_lists:
 * each file is a JSON array of records with optional fields in canonical
 * order — name, aliases, category, context, island, birth_year, death_year —
 * where null/""/[] values are omitted entirely. The normalized lookup key of
 * a record is aliases[0] (every list converted from a legacy keyed map keeps
 * the old key there); records without aliases key off the normalized name.
 */
class NameListRecords
{
    public const FIELDS = ['name', 'aliases', 'category', 'context', 'island', 'birth_year', 'death_year'];

    public static function make(string $name, array $aliases = [], string $category = '', string $context = '', string $island = ''): array
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Name-list record requires a non-empty name');
        }
        $record = ['name' => $name];
        if ($aliases !== []) {
            $record['aliases'] = $aliases;
        }
        if ($category !== '') {
            $record['category'] = $category;
        }
        if ($context !== '') {
            $record['context'] = $context;
        }
        if ($island !== '') {
            $record['island'] = $island;
        }
        return $record;
    }

    public static function normalizedKey(array $record): string
    {
        $alias = $record['aliases'][0] ?? '';
        if (is_string($alias) && $alias !== '') {
            return $alias;
        }
        return CorpusScanner::normalizeWord((string)($record['name'] ?? ''));
    }

    public static function isRecordArray(mixed $decoded): bool
    {
        return is_array($decoded)
            && $decoded !== []
            && array_is_list($decoded)
            && is_array($decoded[0])
            && array_key_exists('name', $decoded[0]);
    }

    public static function recordsToSet(array $records): array
    {
        $set = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $key = self::normalizedKey($record);
            if ($key !== '') {
                $set[$key] = true;
            }
        }
        return $set;
    }
}
