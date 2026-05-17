<?php

declare(strict_types=1);

namespace Electus\Models;

use Electus\Core\Database;

class Category
{
    public static function forEvent(int $eventId): array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE event_id = ? ORDER BY sort_order, name');
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(int $eventId, string $name, int $sortOrder = 0): int
    {
        $pdo  = Database::get();
        $slug = self::generateSlug($name);
        $stmt = $pdo->prepare('INSERT INTO categories (event_id, name, slug, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->execute([$eventId, $name, $slug, $sortOrder]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $name, int $sortOrder): void
    {
        $pdo  = Database::get();
        $slug = self::generateSlug($name);
        $stmt = $pdo->prepare('UPDATE categories SET name = ?, slug = ?, sort_order = ? WHERE id = ?');
        $stmt->execute([$name, $slug, $sortOrder, $id]);
    }

    public static function delete(int $id): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function generateSlug(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s-]/u', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', trim($slug));
        return trim($slug, '-');
    }
}
