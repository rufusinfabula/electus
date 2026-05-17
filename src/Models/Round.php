<?php

declare(strict_types=1);

namespace Electus\Models;

use Electus\Core\Database;

class Round
{
    public static function forEvent(int $eventId): array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT r.*, p.label AS parent_label
             FROM event_rounds r
             LEFT JOIN event_rounds p ON p.id = r.parent_round_id
             WHERE r.event_id = ?
             ORDER BY r.round_number'
        );
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM event_rounds WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row && $row['config']) {
            $row['config'] = json_decode($row['config'], true) ?? [];
        } else {
            $row['config'] = [];
        }
        return $row ?: null;
    }

    public static function create(int $eventId, array $data): int
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO event_rounds
             (event_id, round_number, label, model, status, opens_at, closes_at,
              config, parent_round_id, top_n_to_promote)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $eventId,
            (int) $data['round_number'],
            $data['label'] ?? null,
            $data['model'],
            $data['status'],
            $data['opens_at'] ?: null,
            $data['closes_at'] ?: null,
            json_encode($data['config'] ?? []),
            $data['parent_round_id'] ?: null,
            $data['top_n_to_promote'] ?: null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'UPDATE event_rounds SET
             round_number = ?, label = ?, model = ?, status = ?,
             opens_at = ?, closes_at = ?, config = ?,
             parent_round_id = ?, top_n_to_promote = ?
             WHERE id = ?'
        );
        $stmt->execute([
            (int) $data['round_number'],
            $data['label'] ?? null,
            $data['model'],
            $data['status'],
            $data['opens_at'] ?: null,
            $data['closes_at'] ?: null,
            json_encode($data['config'] ?? []),
            $data['parent_round_id'] ?: null,
            $data['top_n_to_promote'] ?: null,
            $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('DELETE FROM event_rounds WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('UPDATE event_rounds SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    // Returns the currently active round for a given event (public voting)
    public static function activeForEvent(int $eventId): ?array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            "SELECT * FROM event_rounds
             WHERE event_id = ? AND status = 'active'
             AND (opens_at IS NULL OR opens_at <= NOW())
             AND (closes_at IS NULL OR closes_at >= NOW())
             ORDER BY round_number LIMIT 1"
        );
        $stmt->execute([$eventId]);
        $row = $stmt->fetch();
        if ($row && $row['config']) {
            $row['config'] = json_decode($row['config'], true) ?? [];
        } else {
            if ($row) $row['config'] = [];
        }
        return $row ?: null;
    }
}
