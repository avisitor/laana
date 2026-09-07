<?php

// Path to your CSV file
$csvFile = __DIR__ . "/name_lists/historical_figures.csv";
$csvFile = __DIR__ . "/name_lists/literary_figures.csv";
if( $argc > 1 ) {
    $csvFile = $argv[1];
}

// Open the CSV
$handle = fopen($csvFile, "r");
if (!$handle) {
    die("Unable to open CSV file $csvFile.");
}

$records = [];
$lineNumber = 0;

while (($row = fgetcsv($handle)) !== false) {

    // Skip header if present
    if ($lineNumber === 0 && $row[0] === "name") {
        $lineNumber++;
        continue;
    }

    // CSV columns (Option C):
    // 0: name
    // 1: aliases (semicolon-separated)
    // 2: category
    // 3: context
    // 4: island
    // 5: birth_year
    // 6: death_year

    $name       = trim($row[0]);
    $aliasesRaw = trim($row[1]);
    $category   = trim($row[2]);
    $context    = trim($row[3]);
    $island     = trim($row[4]);
    $birthYear  = trim($row[5]);
    $deathYear  = trim($row[6]);

    // Convert aliases to array
    $aliases = [];
    if ($aliasesRaw !== "") {
        $aliases = array_map("trim", explode(";", $aliasesRaw));
    }

    // Convert empty year fields to null
    $birthYear = ($birthYear === "" ? null : intval($birthYear));
    $deathYear = ($deathYear === "" ? null : intval($deathYear));

    $records[] = [
        "name"       => $name,
        "aliases"    => $aliases,
        "category"   => $category,
        "context"    => $context,
        "island"     => $island,
        "birth_year" => $birthYear,
        "death_year" => $deathYear
    ];

    $lineNumber++;
}

fclose($handle);

// Output JSON
header("Content-Type: application/json; charset=utf-8");
echo json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

