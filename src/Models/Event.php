<?php

declare(strict_types=1);

namespace Electus\Models;

use Electus\Core\Database;
use PDO;

class Event
{
    public static function all(): array
    {
        $pdo = Database::get();
        return $pdo->query(
            'SELECT e.*, u.name AS creator_name
             FROM events e
             JOIN users u ON u.id = e.created_by
             ORDER BY e.created_at DESC'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findBySlug(string $slug): ?array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare("SELECT * FROM events WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data, int $createdBy): int
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO events
             (name, slug, description, type, access_mode, email_verification,
              results_public, theme_preset, theme_colors, cat_term, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['description'] ?? null,
            $data['type'],
            $data['access_mode'],
            (int) ($data['email_verification'] ?? 0),
            (int) ($data['results_public'] ?? 1),
            $data['theme_preset'] ?? null,
            $data['theme_colors'] ?? null,
            $data['cat_term'] ?? null,
            $data['status'],
            $createdBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'UPDATE events SET
             name = ?, slug = ?, description = ?, type = ?, access_mode = ?,
             email_verification = ?, results_public = ?, theme_preset = ?, theme_colors = ?,
             cat_term = ?, status = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['description'] ?? null,
            $data['type'],
            $data['access_mode'],
            (int) ($data['email_verification'] ?? 0),
            (int) ($data['results_public'] ?? 1),
            $data['theme_preset'] ?? null,
            $data['theme_colors'] ?? null,
            $data['cat_term'] ?? null,
            $data['status'],
            $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('DELETE FROM events WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function slugExists(string $slug, int $excludeId = 0): bool
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('SELECT id FROM events WHERE slug = ? AND id != ? LIMIT 1');
        $stmt->execute([$slug, $excludeId]);
        return (bool) $stmt->fetch();
    }

    public static function generateSlug(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s-]/u', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', trim($slug));
        return trim($slug, '-');
    }
}
