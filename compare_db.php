#!/usr/bin/env php
<?php

// compare_db_all.php

declare(strict_types=1);

/**
 * Lädt Umgebungsvariablen aus einer `.env`-Datei und setzt sie in putenv, $_ENV und $_SERVER.
 *
 * @param string $filePath Der Pfad zur .env-Datei.
 * @throws Exception Falls die Datei nicht existiert.
 */
function loadEnv(string $filePath): void
{
    if (!file_exists($filePath)) {
        throw new Exception("The .env file was not found. Please create one by copying the .env.example file and renaming it to .env.");
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new Exception("Unable to read the .env file.");
    }

    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Kommentare überspringen
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

/**
 * Erstellt eine neue PDO-Datenbankverbindung zu einer MySQL/MariaDB-Datenbank.
 *
 * Wenn $database leer ist, wird keine Datenbank ausgewählt.
 *
 * @param string $host     Die Hostadresse der Datenbank.
 * @param string $user     Der Benutzername.
 * @param string $password Das Passwort.
 * @param string|null $database Der Name der Datenbank (optional).
 * @return PDO
 * @throws PDOException Falls die Verbindung fehlschlägt.
 */
function getDbConnection(string $host, string $user, string $password, ?string $database = null): PDO
{
    $dsn = "mysql:host=$host;charset=utf8mb4";
    if ($database !== null && $database !== '') {
        $dsn .= ";dbname=$database";
    }
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/**
 * Ermittelt alle Datenbanken des Servers und filtert Systemdatenbanken heraus.
 *
 * @param PDO $pdo Die PDO-Verbindung zum Server (ohne spezielle Datenbank).
 * @return array Liste der Datenbanknamen.
 */
function getDatabases(PDO $pdo): array
{
    $stmt = $pdo->query("SHOW DATABASES");
    $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Systemdatenbanken ausschließen
    $exclude = ['mysql', 'information_schema', 'performance_schema', 'sys'];
    return array_values(array_filter($databases, fn($db) => !in_array($db, $exclude, true)));
}

/**
 * Liefert die Konfiguration (SQL-Abfrage, Erzeugungsfunktion und Spaltenname) für den angegebenen Typ.
 *
 * @param string $type Erlaubte Werte: 'tables', 'views', 'procedures', 'functions'.
 * @return array{query: string, createQuery: callable(string): string, column: string}
 * @throws InvalidArgumentException Falls ein unbekannter Typ übergeben wird.
 */
function getQueryAndColumn(string $type): array
{
    $config = [
        'tables' => [
            'query' => "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT REGEXP '_(mv|pv)$'",
            'createQuery' => function (string $name) {
                return "SHOW CREATE TABLE `$name`";
            },
            'column' => 'Create Table'
        ],
        'views' => [
            'query' => "SELECT TABLE_NAME FROM information_schema.views WHERE table_schema = ?",
            'createQuery' => function (string $name) {
                return "SHOW CREATE VIEW `$name`";
            },
            'column' => 'Create View'
        ],
        'procedures' => [
            'query' => "SELECT ROUTINE_NAME FROM information_schema.routines WHERE routine_schema = ? AND ROUTINE_TYPE = 'PROCEDURE'",
            'createQuery' => function (string $name) {
                return "SHOW CREATE PROCEDURE `$name`";
            },
            'column' => 'Create Procedure'
        ],
        'functions' => [
            'query' => "SELECT ROUTINE_NAME FROM information_schema.routines WHERE routine_schema = ? AND ROUTINE_TYPE = 'FUNCTION'",
            'createQuery' => function (string $name) {
                return "SHOW CREATE FUNCTION `$name`";
            },
            'column' => 'Create Function'
        ],
    ];

    if (!isset($config[$type])) {
        throw new InvalidArgumentException("Unknown type: $type");
    }
    return $config[$type];
}

/**
 * Führt die "SHOW CREATE"-Abfrage für das gegebene Objekt aus, verarbeitet die erhaltene CREATE-Anweisung und entfernt
 * spezifische Elemente wie AUTO_INCREMENT und DEFINER.
 *
 * @param PDO      $pdo             Die PDO-Datenbankverbindung.
 * @param string   $name            Der Name des Datenbankobjekts.
 * @param callable $createQueryFunc Callback, die den Objektnamen entgegennimmt und die entsprechende "SHOW CREATE"-Abfrage zurückgibt.
 * @param string   $column          Der Spaltenname, aus der das CREATE-Statement ausgelesen wird.
 * @return string Die verarbeitete CREATE-Anweisung.
 * @throws Exception Falls die Abfrage fehlschlägt oder erwartete Indizes fehlen.
 */
function processCreateStatement(PDO $pdo, string $name, callable $createQueryFunc, string $column): string
{
    $createQuery = $createQueryFunc($name);
    $stmtCreate = $pdo->query($createQuery);
    if ($stmtCreate === false) {
        throw new Exception("Query failed: $createQuery");
    }
    $result = $stmtCreate->fetch(PDO::FETCH_ASSOC);
    if (!is_array($result) || !array_key_exists($column, $result)) {
        throw new Exception("Expected column '$column' not found in the result.");
    }
    $createStmt = $result[$column];

    // AUTO_INCREMENT entfernen
    $createStmt = preg_replace('/AUTO_INCREMENT=\d+\s*/i', '', $createStmt);
    if (!is_string($createStmt)) {
        $createStmt = is_array($createStmt) ? implode("\n", $createStmt) : '';
    }

    // DEFINER entfernen
    $createStmt = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/i', '', $createStmt);
    if ($createStmt === null) {
        throw new Exception("Unexpected null returned from preg_replace for DEFINER removal.");
    }

    return $createStmt;
}

/**
 * Ermittelt die CREATE-Anweisungen für Tabellen, Views, Prozeduren oder Funktionen in der angegebenen Datenbank.
 *
 * @param PDO    $pdo      Die PDO-Datenbankverbindung.
 * @param string $database Der Name der Datenbank.
 * @param string $type     Erlaubte Werte: 'tables', 'views', 'procedures', 'functions'.
 * @return array<string, string> Assoziatives Array, Schlüssel: Objektname, Wert: CREATE-Anweisung.
 * @throws Exception Falls erwartete Indizes fehlen.
 */
function getCreateStatements(PDO $pdo, string $database, string $type): array
{
    $config = getQueryAndColumn($type);
    $stmt = $pdo->prepare($config['query']);
    $stmt->execute([$database]);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $nameKey = ($type === 'tables' || $type === 'views') ? 'TABLE_NAME' : 'ROUTINE_NAME';
        if (!isset($row[$nameKey]) || !is_string($row[$nameKey])) {
            throw new Exception("Expected index '$nameKey' not found or not a string.");
        }
        $name = $row[$nameKey];
        $items[$name] = processCreateStatement($pdo, $name, $config['createQuery'], $config['column']);
    }
    return $items;
}

/**
 * Vergleicht die CREATE-Definitionen zwischen DEV und PROD für einen bestimmten Objekttyp.
 *
 * @param string $label Ein Label für die Ausgabe (z.B. "Tables").
 * @param array<string, string> $dev   CREATE-Definitionen aus DEV.
 * @param array<string, string> $prod  CREATE-Definitionen aus PROD.
 * @return bool Gibt true zurück, wenn keine Unterschiede bestehen.
 */
function compareStructures(string $label, array $dev, array $prod): bool
{
    $diffs = [];
    $allKeys = array_unique(array_merge(array_keys($dev), array_keys($prod)));

    foreach ($allKeys as $key) {
        $devStmt = $dev[$key] ?? null;
        $prodStmt = $prod[$key] ?? null;

        if ($devStmt !== $prodStmt) {
            $diffs[] = "\033[31m❌ Difference in $label: $key\033[0m";

            if ($devStmt === null) {
                $diffs[] = "\033[31m❌ Not found in DEV\033[0m";
            } elseif ($prodStmt === null) {
                $diffs[] = "\033[31m❌ Not found in PROD\033[0m";
            } else {
                $diffs[] = generateDiff($devStmt, $prodStmt);
            }
        }
    }

    if (count($diffs) > 0) {
        echo implode("\n", $diffs) . "\n";
        return false;
    }

    echo "\033[32m✅ No differences in $label\033[0m\n";
    return true;
}

/**
 * Erzeugt einen farbcodierten Diff zwischen zwei CREATE-Statements mit Kontext.
 *
 * @param string $devStmt  CREATE-Definition aus DEV.
 * @param string $prodStmt CREATE-Definition aus PROD.
 * @param int    $context  Anzahl Kontextzeilen (Standard: 3).
 * @return string Diff-Output.
 */
function generateDiff(string $devStmt, string $prodStmt, int $context = 3): string
{
    $devLines = explode("\n", $devStmt);
    $prodLines = explode("\n", $prodStmt);
    $numDev = count($devLines);
    $numProd = count($prodLines);
    $maxLines = max($numDev, $numProd);

    $diffIndices = [];
    for ($i = 0; $i < $maxLines; $i++) {
        $devLine = $devLines[$i] ?? '';
        $prodLine = $prodLines[$i] ?? '';
        if ($devLine !== $prodLine) {
            $diffIndices[] = $i;
        }
    }

    if (empty($diffIndices)) {
        return "Keine Unterschiede gefunden.";
    }

    $blocks = [];
    $blockStart = null;
    $blockEnd = null;
    foreach ($diffIndices as $diffIndex) {
        if ($blockStart === null) {
            $blockStart = max(0, $diffIndex - $context);
            $blockEnd = min($maxLines - 1, $diffIndex + $context);
        } else {
            if ($diffIndex - $context <= $blockEnd + 1) {
                $blockEnd = min($maxLines - 1, $diffIndex + $context);
            } else {
                $blocks[] = [$blockStart, $blockEnd];
                $blockStart = max(0, $diffIndex - $context);
                $blockEnd = min($maxLines - 1, $diffIndex + $context);
            }
        }
    }
    if ($blockStart !== null) {
        $blocks[] = [$blockStart, $blockEnd];
    }

    $outputLines = [];
    $prevBlockEnd = -1;
    foreach ($blocks as $block) {
        list($start, $end) = $block;
        if ($start > $prevBlockEnd + 1) {
            $outputLines[] = "...";
        }
        for ($i = $start; $i <= $end; $i++) {
            $devLine = $devLines[$i] ?? '';
            $prodLine = $prodLines[$i] ?? '';
            if ($devLine === $prodLine) {
                $outputLines[] = "  $devLine";
            } else {
                $outputLines[] = "\033[31m- $devLine\033[0m";
                $outputLines[] = "\033[32m+ $prodLine\033[0m";
            }
        }
        $prevBlockEnd = $end;
    }

    return implode("\n", $outputLines);
}


// Hauptskript
try {
    loadEnv(__DIR__ . '/.env');

    // Lese Verbindungsdaten für DEV und PROD aus den Umgebungsvariablen
    $devHost = getenv('DEV_DB_HOST');
    $devUser = getenv('DEV_DB_USER');
    $devPass = getenv('DEV_DB_PASS');
    $prodHost = getenv('PROD_DB_HOST');
    $prodUser = getenv('PROD_DB_USER');
    $prodPass = getenv('PROD_DB_PASS');

    if ($devHost === false || $devUser === false || $devPass === false) {
        throw new Exception('One or more environment variables for the DEV database connection are missing.');
    }
    if ($prodHost === false || $prodUser === false || $prodPass === false) {
        throw new Exception('One or more environment variables for the PROD database connection are missing.');
    }

    // Verbindung zum Server (ohne spezifische DB) herstellen
    $devServerPdo = getDbConnection($devHost, $devUser, $devPass);
    $prodServerPdo = getDbConnection($prodHost, $prodUser, $prodPass);

    // Erhalte alle nicht-system-Datenbanken
    $devDatabases = getDatabases($devServerPdo);
    $prodDatabases = getDatabases($prodServerPdo);

    // Ausgabe der gefundenen Datenbanken
    echo "DEV databases: " . implode(', ', $devDatabases) . "\n";
    echo "PROD databases: " . implode(', ', $prodDatabases) . "\n";

    $errors = false;

    // Vergleiche nur Datenbanken, die in beiden Umgebungen existieren
    $commonDatabases = array_intersect($devDatabases, $prodDatabases);
    foreach ($commonDatabases as $database) {
        echo "\n\033[34m== Vergleiche Datenbank: $database ==\033[0m\n";

        // Für jede Datenbank wird eine separate Verbindung aufgebaut
        $devPdo = getDbConnection($devHost, $devUser, $devPass, $database);
        $prodPdo = getDbConnection($prodHost, $prodUser, $prodPass, $database);

        $checks = [
            'tables' => 'Tables',
            'views' => 'Views',
            'procedures' => 'Procedures',
            'functions' => 'Functions'
        ];

        foreach ($checks as $type => $label) {
            $devStruct = getCreateStatements($devPdo, $database, $type);
            $prodStruct = getCreateStatements($prodPdo, $database, $type);

            if (!compareStructures($label, $devStruct, $prodStruct)) {
                $errors = true;
            }
        }
    }

    // Überprüfe, ob es Datenbanken gibt, die nur in einer Umgebung existieren
    $devOnly = array_diff($devDatabases, $prodDatabases);
    $prodOnly = array_diff($prodDatabases, $devDatabases);

    if (!empty($devOnly)) {
        echo "\n\033[33mWARNUNG: Die folgenden Datenbanken existieren nur in DEV: " . implode(', ', $devOnly) . "\033[0m\n";
        $errors = true;
    }
    if (!empty($prodOnly)) {
        echo "\n\033[33mWARNUNG: Die folgenden Datenbanken existieren nur in PROD: " . implode(', ', $prodOnly) . "\033[0m\n";
        $errors = true;
    }

    if ($errors) {
        exit(1);
    }

    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
