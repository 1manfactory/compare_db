#!/usr/bin/env php
<?php

// compare_db.php

declare(strict_types=1);

/**
 * Lädt Umgebungsvariablen aus einer `.env`-Datei und setzt sie in `putenv`, `$_ENV` und `$_SERVER`.
 *
 * Diese Funktion liest eine `.env`-Datei zeilenweise ein, ignoriert leere Zeilen und Kommentare
 * und setzt Umgebungsvariablen basierend auf `KEY=VALUE`-Einträgen.
 *
 * @param string $filePath Der Pfad zur `.env`-Datei.
 *
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
			continue; // Skip comments
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
 * Diese Funktion stellt eine Verbindung zur angegebenen Datenbank her und setzt die
 * Fehlerbehandlung auf `PDO::ERRMODE_EXCEPTION`, um Fehler als Exceptions auszugeben.
 * Standardmäßig wird `PDO::FETCH_ASSOC` als Fetch-Mode genutzt.
 *
 * @param string $host     Die Hostadresse der Datenbank (z. B. `localhost` oder eine IP-Adresse).
 * @param string $user     Der Benutzername für die Datenbankverbindung.
 * @param string $password Das Passwort für die Datenbankverbindung.
 * @param string $database Der Name der zu verwendenden Datenbank.
 *
 * @return PDO Ein PDO-Objekt, das die aktive Datenbankverbindung repräsentiert.
 *
 * @throws PDOException Falls die Verbindung fehlschlägt.
 */
function getDbConnection(string $host, string $user, string $password, string $database): PDO
{
	return new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $user, $password, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	]);
}


/**
 * Liefert die Konfiguration (SQL-Abfrage, Erzeugungsfunktion und Spaltenname) für den angegebenen Typ.
 *
 * @param string $type Der Typ des Datenbankobjekts. Erlaubte Werte: 'tables', 'views', 'procedures', 'functions'.
 * @return array{query: string, createQuery: callable(string): string, column: string} Das Konfigurationsarray.
 *
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
 * @param string   $name            Der Name des Datenbankobjekts (Tabelle, View, Prozedur oder Funktion).
 * @param callable $createQueryFunc Eine Callback-Funktion, die den Objektnamen entgegennimmt und die entsprechende
 *                                  "SHOW CREATE"-Abfrage als String zurückgibt.
 * @param string   $column          Der Spaltenname, aus der das CREATE-Statement ausgelesen wird.
 *
 * @return string Die verarbeitete CREATE-Anweisung.
 *
 * @throws Exception Falls die Abfrage fehlschlägt, der erwartete Spaltenname nicht gefunden wird oder die
 *                   regulären Ausdrucksoperationen unerwartete Ergebnisse liefern.
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
 * Ermittelt die CREATE-Anweisungen (z. B. für Tabellen, Views, Prozeduren oder Funktionen) aus der angegebenen Datenbank.
 *
 * @param PDO    $pdo      Die PDO-Datenbankverbindung.
 * @param string $database Der Name der zu überprüfenden Datenbank.
 * @param string $type     Der Typ des Datenbankobjekts. Erlaubte Werte sind 'tables', 'views', 'procedures' oder 'functions'.
 *
 * @return array<string, string> Ein assoziatives Array, in dem die Schlüssel die Objektnamen (Tabelle, View, etc.) und die Werte die
 *                                entsprechenden CREATE-Anweisungen sind.
 *
 * @throws Exception Falls der erwartete Index ('TABLE_NAME' oder 'ROUTINE_NAME') im Abfrageergebnis fehlt oder nicht vom Typ string ist.
 */
function getCreateStatements(PDO $pdo, string $database, string $type): array
{
	$config = getQueryAndColumn($type);
	$stmt = $pdo->prepare($config['query']);
	$stmt->execute([$database]);

	$items = [];
	while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		/** @var array<string, mixed> $row */
		$nameKey = ($type === 'tables' || $type === 'views') ? 'TABLE_NAME' : 'ROUTINE_NAME';
		if (!isset($row[$nameKey]) || !is_string($row[$nameKey])) {
			throw new Exception("Expected index '$nameKey' not found or not a string.");
		}
		$name = $row[$nameKey];

		// Hier erfolgt die Verarbeitung des CREATE-Statements (über processCreateStatement)
		$items[$name] = processCreateStatement($pdo, $name, $config['createQuery'], $config['column']);
	}
	return $items;
}


/**
 * Vergleicht die `CREATE`-Definitionen von Tabellen, Views, Prozeduren oder Funktionen zwischen Entwicklungs- und Produktionsdatenbank.
 *
 * Diese Funktion überprüft, ob sich die Strukturen zwischen der Entwicklungs- (DEV) und der Produktionsumgebung (PROD) unterscheiden.
 * Falls Unterschiede bestehen, werden sie farblich hervorgehoben in der Konsole ausgegeben. Wenn keine Unterschiede vorhanden sind,
 * wird eine grüne Erfolgsmeldung angezeigt.
 *
 * @param string               $label Ein Label für die Ausgabe (z. B. "Tables", "Views", "Procedures", "Functions").
 * @param array<string, string> $dev   Ein assoziatives Array der `CREATE`-Definitionen aus der Entwicklungsdatenbank (DEV).
 *                                     Der Schlüssel ist der Objektname, der Wert die SQL-Definition.
 * @param array<string, string> $prod  Ein assoziatives Array der `CREATE`-Definitionen aus der Produktionsdatenbank (PROD).
 *                                     Der Schlüssel ist der Objektname, der Wert die SQL-Definition.
 *
 * @return bool Gibt `true` zurück, wenn keine Unterschiede festgestellt wurden, ansonsten `false`.
 */
function compareStructures(string $label, array $dev, array $prod): bool
{
	$diffs = [];
	$allKeys = array_unique(array_merge(array_keys($dev), array_keys($prod)));

	foreach ($allKeys as $key) {
		$devStmt = $dev[$key] ?? null;
		$prodStmt = $prod[$key] ?? null;

		#echo $devStmt.PHP_EOL;
		#echo $prodStmt.PHP_EOL;

		if ($devStmt !== $prodStmt) {
			$diffs[] = "\033[31m❌ Difference in $label: $key\033[0m"; // Rote Markierung

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
		// @phpcs:ignore
		echo implode("\n", $diffs) . "\n";
		return false;
	}

	// @phpcs:ignore
	echo "\033[32m✅ No differences in $label\033[0m\n"; // Grün für OK
	return true;
}


/**
 * Erstellt einen farbcodierten, zeilenweisen Diff zwischen zwei `CREATE`-SQL-Statements mit Kontext.
 *
 * Nur die Zeilen, in denen Unterschiede auftreten, sowie einige Zeilen davor und danach werden angezeigt.
 *
 * @param string $devStmt  Die `CREATE`-SQL-Definition aus der Entwicklungsdatenbank.
 * @param string $prodStmt Die `CREATE`-SQL-Definition aus der Produktionsdatenbank.
 * @param int    $context  Anzahl der Kontextzeilen vor und nach einem Unterschied (Standard: 3).
 *
 * @return string Eine Zeichenkette mit dem farblich hervorgehobenen Diff-Output.
 */
function generateDiff(string $devStmt, string $prodStmt, int $context = 3): string
{
	$devLines = explode("\n", $devStmt);
	$prodLines = explode("\n", $prodStmt);
	$numDev = count($devLines);
	$numProd = count($prodLines);
	$maxLines = max($numDev, $numProd);

	// Ermitteln der Indizes, an denen sich die Zeilen unterscheiden
	$diffIndices = [];
	for ($i = 0; $i < $maxLines; $i++) {
		$devLine = $devLines[$i] ?? '';
		$prodLine = $prodLines[$i] ?? '';
		if ($devLine !== $prodLine) {
			$diffIndices[] = $i;
		}
	}

	// Falls es keine Unterschiede gibt, sofort zurückgeben
	if (empty($diffIndices)) {
		return "Keine Unterschiede gefunden.";
	}

	// Bestimme Blöcke (Bereiche) mit Kontext, in denen Unterschiede enthalten sind.
	$blocks = [];
	$blockStart = null;
	$blockEnd = null;
	foreach ($diffIndices as $diffIndex) {
		if ($blockStart === null) {
			$blockStart = max(0, $diffIndex - $context);
			$blockEnd = min($maxLines - 1, $diffIndex + $context);
		} else {
			// Liegt der nächste Unterschied im Kontext des aktuellen Blocks?
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

	// Baue den Ausgabe-String anhand der Blöcke
	$outputLines = [];
	$prevBlockEnd = -1;
	foreach ($blocks as $block) {
		list($start, $end) = $block;
		// Bei Lücken zwischen Blöcken einen Trenner einfügen
		if ($start > $prevBlockEnd + 1) {
			$outputLines[] = "...";
		}
		for ($i = $start; $i <= $end; $i++) {
			$devLine = $devLines[$i] ?? '';
			$prodLine = $prodLines[$i] ?? '';
			if ($devLine === $prodLine) {
				// Unveränderte Zeile ohne Farbcodierung
				$outputLines[] = "  $devLine";
			} else {
				// Unterschiede farblich hervorheben:
				// - Rot für die Zeile aus der DEV-Definition
				// - Grün für die Zeile aus der PROD-Definition
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

	$devHost = getenv('DEV_DB_HOST');
	$devUser = getenv('DEV_DB_USER');
	$devPass = getenv('DEV_DB_PASS');
	$devDb   = getenv('DEV_DB_NAME');
	if ($devHost === false || $devUser === false || $devPass === false || $devDb === false) {
		throw new Exception('One or more environment variables for the database connection are missing.');
	}
	$devPdo = getDbConnection($devHost, $devUser, $devPass, $devDb);

	$prodHost = getenv('PROD_DB_HOST');
	$prodUser = getenv('PROD_DB_USER');
	$prodPass = getenv('PROD_DB_PASS');
	$prodDb   = getenv('PROD_DB_NAME');
	if ($prodHost === false || $prodUser === false || $prodPass === false || $prodDb === false) {
		throw new Exception('One or more production environment variables for the database connection are missing.');
	}
	$prodPdo = getDbConnection($prodHost, $prodUser, $prodPass, $prodDb);

	$checks = [
		'tables' => 'Tables',
		'views' => 'Views',
		'procedures' => 'Procedures',
		'functions' => 'Functions'
	];

	$errors = false;

	foreach ($checks as $type => $label) {
		$devDb = getenv('DEV_DB_NAME');
		if ($devDb === false) {
			throw new Exception('DEV_DB_NAME environment variable is missing.');
		}

		$prodDb = getenv('PROD_DB_NAME');
		if ($prodDb === false) {
			throw new Exception('PROD_DB_NAME environment variable is missing.');
		}

		$devStruct = getCreateStatements($devPdo, $devDb, $type);
		$prodStruct = getCreateStatements($prodPdo, $prodDb, $type);

		if (!compareStructures($label, $devStruct, $prodStruct)) {
			$errors = true;
		}
	}

	if ($errors) {
		exit(1); // Fehlerstatus für Git Hook
	}

	exit(0); // Erfolgreich
} catch (Exception $e) {
	fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
	exit(1);
}
