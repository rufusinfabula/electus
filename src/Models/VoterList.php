<?php

declare(strict_types=1);

namespace Electus\Models;

use Electus\Core\Database;

class VoterList
{
    public static function findByToken(string $token, int $eventId): ?array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT * FROM voter_lists WHERE token = ? AND event_id = ? LIMIT 1'
        );
        $stmt->execute([$token, $eventId]);
        return $stmt->fetch() ?: null;
    }

    public static function findByEmail(string $email, int $eventId): ?array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT * FROM voter_lists WHERE email = ? AND event_id = ? LIMIT 1'
        );
        $stmt->execute([$email, $eventId]);
        return $stmt->fetch() ?: null;
    }

    public static function register(
        int $eventId,
        string $email,
        string $name = '',
        bool $consentMarketing = false,
        bool $approved = true,
        float $weight = 1.0
    ): array {
        $pdo   = Database::get();
        $token = bin2hex(random_bytes(32));

        $stmt = $pdo->prepare(
            'INSERT INTO voter_lists
             (event_id, email, name, token, source, consent_marketing, approved, weight)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $eventId,
            $email,
            $name,
            $token,
            'self_registered',
            $consentMarketing ? 1 : 0,
            $approved ? 1 : 0,
            $weight,
        ]);

        return ['token' => $token, 'id' => (int) $pdo->lastInsertId()];
    }

    public static function markInvited(int $id): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('UPDATE voter_lists SET invited_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function forEvent(int $eventId): array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT * FROM voter_lists WHERE event_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }

    public static function generateVerificationToken(int $voterId): string
    {
        $token = bin2hex(random_bytes(32));
        $pdo   = Database::get();
        $stmt  = $pdo->prepare('UPDATE voter_lists SET token = ? WHERE id = ?');
        $stmt->execute([$token, $voterId]);
        return $token;
    }
}
