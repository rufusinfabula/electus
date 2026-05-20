<?php
/**
 * Import Google Forms candidature CSV → Electus (dati grezzi).
 *
 * Usage (CLI):
 *   php import_forms_csv.php /path/to/file.csv [--dry-run] [--event-id=N] [--round-id=N]
 *
 * Dry-run: mostra riepilogo senza scrivere nel DB.
 * --event-id e --round-id sovrascrivono i default (evento 3, round 3).
 * Le categorie vengono lette dal DB in base all'event-id (ordine sort_order).
 *
 * Import:
 *   - voter_lists   (email univoche, approved=1, token_used=1)
 *   - candidates    (testo grezzo così com'è, source=user_input)
 *   - votes         (un voto per nominazione per votante)
 *
 * Il dedup di Electus lavora sui dati così inseriti.
 */

declare(strict_types=1);

function normalizeCandidate(string $input): string
{
    $s = mb_strtoupper(trim($input));
    $s = preg_replace('/^(il|la|le|i|gli|un|una|l\'|the|les|le|un|une)\s+/u', '', $s);
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$csvPath  = null;
$dryRun   = false;
$eventId  = 3;
$roundId  = 3;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if (preg_match('/^--event-id=(\d+)$/', $arg, $m)) { $eventId = (int)$m[1]; continue; }
    if (preg_match('/^--round-id=(\d+)$/', $arg, $m)) { $roundId = (int)$m[1]; continue; }
    if (!str_starts_with($arg, '--')) $csvPath = $arg;
}

if (!$csvPath || !file_exists($csvPath)) {
    echo "Uso: php import_forms_csv.php <file.csv> [--dry-run] [--event-id=N] [--round-id=N]\n";
    exit(1);
}

$cfg = require __DIR__ . '/../config/config.php';
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $cfg['db']['host'], $cfg['db']['port'], $cfg['db']['name'], $cfg['db']['charset']);
$pdo = new PDO($dsn, $cfg['db']['user'], $cfg['db']['pass'], [
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
]);

// Legge le categorie dell'evento dal DB in ordine sort_order → colonne CSV 5..13
$cats = $pdo->prepare('SELECT id, name FROM categories WHERE event_id = ? ORDER BY sort_order, id');
$cats->execute([$eventId]);
$catRows = $cats->fetchAll();

if (count($catRows) === 0) {
    echo "ERRORE: nessuna categoria trovata per event_id=$eventId\n";
    exit(1);
}

$colToCat  = [];  // col → category_id
$catLabels = [];  // category_id → label
foreach ($catRows as $i => $c) {
    $col = 5 + $i;
    $colToCat[$col]            = (int)$c['id'];
    $catLabels[(int)$c['id']]  = strtoupper($c['name']);
}

echo "Evento ID: $eventId  |  Round ID: $roundId\n";
echo "Categorie: " . implode(', ', array_values($catLabels)) . "\n\n";

// ── Parse CSV ─────────────────────────────────────────────────────────────────

$handle = fopen($csvPath, 'r');
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($handle);
fgetcsv($handle); // skip header

// [email => [catId => [rawName, ...]]]
$data    = [];
$skipped = 0;

while (($row = fgetcsv($handle)) !== false) {
    $email = strtolower(preg_replace('/\s+/', '', trim($row[1] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }

    foreach ($colToCat as $col => $catId) {
        $raw = preg_replace('#^https?://(www\.)?#i', '', trim($row[$col] ?? ''));
        if ($raw === '') continue;
        $data[$email][$catId][] = $raw;
    }
}
fclose($handle);

$totalNoms = 0;
foreach ($data as $cats) {
    foreach ($cats as $names) $totalNoms += count($names);
}

echo "Email valide:        " . count($data) . "\n";
echo "Email non valide:    $skipped\n";
echo "Nominazioni totali:  $totalNoms\n\n";

if ($dryRun) {
    $samples = [];
    foreach ($data as $cats) {
        foreach ($cats as $catId => $names) {
            foreach ($names as $n) $samples[$catId][] = $n;
        }
    }
    foreach ($catLabels as $catId => $label) {
        if (empty($samples[$catId])) continue;
        $unique = array_unique($samples[$catId]);
        echo "══ $label (" . count($unique) . " valori unici) ══\n";
        foreach (array_slice($unique, 0, 10) as $n) {
            echo "  " . substr($n, 0, 100) . "\n";
        }
        echo "\n";
    }
    echo "[dry-run: nessuna modifica al database]\n";
    exit(0);
}

// ── Import ────────────────────────────────────────────────────────────────────

$pdo->beginTransaction();
try {

    // 1. voter_lists ────────────────────────────────────────────────────────────
    echo "Importo voter_lists...\n";
    $stmtIns = $pdo->prepare(
        'INSERT IGNORE INTO voter_lists (event_id, email, token, source, approved, token_used)
         VALUES (?, ?, ?, \'self_registered\', 1, 1)'
    );
    foreach ($data as $email => $_) {
        $stmtIns->execute([$eventId, $email, bin2hex(random_bytes(32))]);
    }
    echo "  -> " . count($data) . " email processate\n";

    // 2. candidates (minuscolo, dedup per nome normalizzato) ─────────────────────
    echo "Importo candidati...\n";

    $stmtCand = $pdo->prepare(
        'INSERT INTO candidates (round_id, category_id, name, canonical_name, source, status)
         VALUES (?, ?, ?, ?, \'user_input\', \'active\')'
    );

    $newCands  = 0;
    $candIndex = [];   // [catId][lowercase_name] = candidate_id

    $existing = $pdo->prepare('SELECT id, category_id, name FROM candidates WHERE round_id = ?');
    $existing->execute([$roundId]);
    foreach ($existing->fetchAll() as $c) {
        $candIndex[(int)$c['category_id']][mb_strtoupper($c['name'])] = (int)$c['id'];
    }

    foreach ($data as $email => $cats) {
        foreach ($cats as $catId => $names) {
            foreach ($names as $raw) {
                $lower = mb_strtoupper(trim($raw));
                if (isset($candIndex[$catId][$lower])) continue;
                $stmtCand->execute([$roundId, $catId, $lower, normalizeCandidate($lower)]);
                $candIndex[$catId][$lower] = (int) $pdo->lastInsertId();
                $newCands++;
            }
        }
    }
    echo "  -> $newCands nuovi candidati inseriti\n";

    // 3. votes ─────────────────────────────────────────────────────────────────
    echo "Importo voti...\n";
    $existingVotes = (int) $pdo->prepare('SELECT COUNT(*) FROM votes WHERE round_id = ?')
        ->execute([$roundId]) ? $pdo->query("SELECT COUNT(*) FROM votes WHERE round_id = $roundId")->fetchColumn() : 0;
    if ($existingVotes > 0) {
        echo "  -> SKIP: il round ha già $existingVotes voti (riesegui solo se vuoi aggiungerne altri).\n";
    } else {
        $stmtVote = $pdo->prepare(
            'INSERT INTO votes (round_id, candidate_id, category_id, value, anonymous_id)
             VALUES (?, ?, ?, 1, ?)'
        );
        $newVotes = 0;
        foreach ($data as $email => $cats) {
            $anonId = bin2hex(random_bytes(32));
            foreach ($cats as $catId => $names) {
                $seen = [];
                foreach ($names as $raw) {
                    $lower = mb_strtoupper(trim($raw));
                    if (in_array($lower, $seen, true)) continue;
                    $seen[] = $lower;
                    $candId = $candIndex[$catId][$lower] ?? null;
                    if (!$candId) continue;
                    $stmtVote->execute([$roundId, $candId, $catId, $anonId]);
                    $newVotes++;
                }
            }
        }
        echo "  -> $newVotes voti inseriti\n";
    }

    $pdo->commit();
    echo "\n✓ Import completato.\n";
    echo "  Vai su Admin → Turno Candidature → Dedup per revisionare i candidati simili.\n";

} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "ERRORE: " . $e->getMessage() . "\n";
    exit(1);
}
