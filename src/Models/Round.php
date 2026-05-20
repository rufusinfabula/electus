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

    private static function sanitizeHtml(?string $html): ?string
    {
        if ($html === null || $html === '') return null;
        $clean = strip_tags($html, '<p><br><strong><em><u><ul><ol><li><a><h2><h3><blockquote>');
        return $clean === '' ? null : $clean;
    }

    public static function create(int $eventId, array $data): int
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO event_rounds
             (event_id, round_number, label, public_description, public_instructions, public_info_box,
              model, status, opens_at, closes_at, config, parent_round_id, top_n_to_promote)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $eventId,
            (int) $data['round_number'],
            $data['label'] ?? null,
            self::sanitizeHtml($data['public_description'] ?? null),
            self::sanitizeHtml($data['public_instructions'] ?? null),
            self::sanitizeHtml($data['public_info_box'] ?? null),
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
             round_number = ?, label = ?, public_description = ?, public_instructions = ?,
             public_info_box = ?, model = ?, status = ?,
             opens_at = ?, closes_at = ?, config = ?,
             parent_round_id = ?, top_n_to_promote = ?
             WHERE id = ?'
        );
        $stmt->execute([
            (int) $data['round_number'],
            $data['label'] ?? null,
            self::sanitizeHtml($data['public_description'] ?? null),
            self::sanitizeHtml($data['public_instructions'] ?? null),
            self::sanitizeHtml($data['public_info_box'] ?? null),
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

    /**
     * Categories active in this round, with advancement settings.
     * Falls back to all event categories if round_category_map is empty.
     */
    public static function categoriesFor(int $roundId): array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT c.*, rcm.advancement_count, rcm.advancement_mode, rcm.next_category_id
             FROM round_category_map rcm
             JOIN categories c ON c.id = rcm.category_id
             WHERE rcm.round_id = ?
             ORDER BY c.sort_order, c.name'
        );
        $stmt->execute([$roundId]);
        $rows = $stmt->fetchAll();

        if (!empty($rows)) return $rows;

        // Fallback for rounds pre-dating the pipeline migration
        $stmt = $pdo->prepare(
            'SELECT c.*, NULL AS advancement_count, \'manual\' AS advancement_mode, NULL AS next_category_id
             FROM categories c
             JOIN event_rounds r ON r.event_id = c.event_id
             WHERE r.id = ?
             ORDER BY c.sort_order, c.name'
        );
        $stmt->execute([$roundId]);
        return $stmt->fetchAll();
    }

    /**
     * Save (replace) the category map for a round.
     * $settings is an array keyed by category_id:
     *   ['mode' => 'auto'|'manual'|'all'|'none', 'count' => int|null, 'next_cat' => int|null]
     */
    public static function saveCategoryMap(int $roundId, array $settings): void
    {
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM round_category_map WHERE round_id = ?')->execute([$roundId]);

        $stmt = $pdo->prepare(
            'INSERT INTO round_category_map
             (round_id, category_id, advancement_mode, advancement_count, next_category_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($settings as $catId => $s) {
            $stmt->execute([
                $roundId,
                (int) $catId,
                $s['mode']    ?? 'manual',
                isset($s['count']) && $s['count'] !== '' ? (int) $s['count'] : null,
                isset($s['next_cat']) && $s['next_cat'] ? (int) $s['next_cat'] : null,
            ]);
        }
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
