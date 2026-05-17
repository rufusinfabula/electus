<?php

declare(strict_types=1);

namespace Electus\Models;

use Electus\Core\Database;

class Candidate
{
    public static function forRound(int $roundId, string $status = 'active'): array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT c.*, cat.name AS category_name
             FROM candidates c
             JOIN categories cat ON cat.id = c.category_id
             WHERE c.round_id = ? AND c.status = ?
             ORDER BY cat.sort_order, cat.name, c.canonical_name'
        );
        $stmt->execute([$roundId, $status]);
        return $stmt->fetchAll();
    }

    public static function forRoundAndCategory(int $roundId, int $categoryId, string $status = 'active'): array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT * FROM candidates WHERE round_id = ? AND category_id = ? AND status = ? ORDER BY canonical_name'
        );
        $stmt->execute([$roundId, $categoryId, $status]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM candidates WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(int $roundId, int $categoryId, string $name, string $source = 'manual'): int
    {
        $pdo           = Database::get();
        $canonicalName = self::normalize($name);
        $stmt          = $pdo->prepare(
            'INSERT INTO candidates (round_id, category_id, name, canonical_name, source) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$roundId, $categoryId, $name, $canonicalName, $source]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $name): void
    {
        $pdo           = Database::get();
        $canonicalName = self::normalize($name);
        $stmt          = $pdo->prepare('UPDATE candidates SET name = ?, canonical_name = ? WHERE id = ?');
        $stmt->execute([$name, $canonicalName, $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('UPDATE candidates SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    public static function delete(int $id): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('DELETE FROM candidates WHERE id = ?');
        $stmt->execute([$id]);
    }

    // Normalize for deduplication: lowercase, strip leading articles, strip punctuation
    public static function normalize(string $input): string
    {
        $s = mb_strtolower(trim($input));
        $s = preg_replace('/^(il|la|le|i|gli|un|una|l\'|the|les|le|un|une)\s+/u', '', $s);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }
}
