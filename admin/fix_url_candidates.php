<?php
/**
 * One-shot CLI: strip http(s):// from existing candidate names in round 3/5/6,
 * then rebuild the dedup queue for those rounds.
 *
 * Usage: php admin/fix_url_candidates.php [--dry-run]
 */

declare(strict_types=1);

$dryRun = in_array('--dry-run', $argv ?? [], true);

$cfg = require __DIR__ . '/../config/config.php';
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $cfg['db']['host'], $cfg['db']['port'], $cfg['db']['name'], $cfg['db']['charset']);
$pdo = new PDO($dsn, $cfg['db']['user'], $cfg['db']['pass'], [
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
]);

function sanitizeName(string $input): string
{
    $s = preg_replace('#^https?:?//(www\.)?#i', '', trim($input));
    return mb_strtoupper(trim($s));
}

function normalizeName(string $input): string
{
    $s = mb_strtolower(trim($input));
    $s = preg_replace('/^(il|la|le|i|gli|un|una|l\'|the|les|le|un|une)\s+/u', '', $s);
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

$roundIds = [3, 5, 6];

// ── Step 1: Find and fix candidates with http:// in their name ────────────────

$stmt = $pdo->prepare(
    "SELECT id, round_id, name, canonical_name FROM candidates
     WHERE round_id IN (" . implode(',', $roundIds) . ")
     AND (name LIKE 'http://%' OR name LIKE 'https://%')"
);
$stmt->execute();
$dirty = $stmt->fetchAll();

echo "Candidati con protocollo URL: " . count($dirty) . "\n\n";

if (empty($dirty)) {
    echo "Nessun candidato da pulire.\n";
} else {
    $upd = $pdo->prepare('UPDATE candidates SET name = ?, canonical_name = ? WHERE id = ?');
    foreach ($dirty as $c) {
        $newName = sanitizeName($c['name']);
        $newNorm = normalizeName($newName);
        echo "  [{$c['id']}] {$c['name']} → $newName\n";
        if (!$dryRun) {
            $upd->execute([$newName, $newNorm, $c['id']]);
        }
    }
}

if ($dryRun) {
    echo "\n[dry-run: nessuna modifica]\n";
    exit(0);
}

// ── Step 2: Clear pending dedup queue for affected rounds ─────────────────────

echo "\nSvuoto la coda dedup pending per round " . implode(', ', $roundIds) . "...\n";
$placeholders = implode(',', array_fill(0, count($roundIds), '?'));
$deleted = $pdo->prepare("DELETE FROM dedup_queue WHERE round_id IN ($placeholders) AND status = 'pending'");
$deleted->execute($roundIds);
echo "  -> " . $deleted->rowCount() . " entry rimosse\n";

// ── Step 3: Rebuild dedup queue for all active candidates ────────────────────

define('ROOT', dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    $path = ROOT . '/src/' . str_replace(['\\', 'Electus/'], ['/', '/'], $class) . '.php';
    if (file_exists($path)) require $path;
});

echo "\nRicostruisco la coda dedup...\n";
$total = 0;
foreach ($roundIds as $rid) {
    $cands = $pdo->prepare("SELECT * FROM candidates WHERE round_id = ? AND status = 'active'");
    $cands->execute([$rid]);
    $rows = $cands->fetchAll();
    $added = 0;
    foreach ($rows as $c) {
        $before = (int) $pdo->prepare("SELECT COUNT(*) FROM dedup_queue WHERE round_id = ? AND status = 'pending'")->execute([$rid])
            ? $pdo->query("SELECT COUNT(*) FROM dedup_queue WHERE round_id = $rid AND status = 'pending'")->fetchColumn()
            : 0;
        \Electus\Models\Deduplication::checkAndQueue($rid, (int)$c['category_id'], $c['name'], (int)$c['id']);
        $after = (int) $pdo->query("SELECT COUNT(*) FROM dedup_queue WHERE round_id = $rid AND status = 'pending'")->fetchColumn();
        if ($after > $before) $added++;
    }
    echo "  Round $rid: " . count($rows) . " candidati scansionati, $added nuove entry in coda\n";
    $total += $added;
}

echo "\n✓ Completato. $total nuove entry totali in coda dedup.\n";
echo "Verifica: SELECT COUNT(*) FROM candidates WHERE name LIKE 'http%' → dovrebbe essere 0\n";
