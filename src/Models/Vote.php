<?php

declare(strict_types=1);

namespace Electus\Models;

use Electus\Core\Database;

class Vote
{
    /**
     * Two-phase cast: atomically marks token as used and saves votes.
     * If no token, just saves votes anonymously.
     *
     * @param array  $round
     * @param array  $votes   [ ['candidate_id'=>int, 'category_id'=>int, 'value'=>float], ... ]
     * @param string|null $token  voter_lists.token, or null for anonymous
     * @throws \RuntimeException on invalid/used token
     */
    public static function cast(array $round, array $votes, ?string $token = null): void
    {
        $pdo = Database::get();
        $pdo->beginTransaction();

        try {
            $weight = 1.0;

            if ($token !== null) {
                // Phase 1: verify and consume token
                $stmt = $pdo->prepare(
                    'SELECT * FROM voter_lists WHERE token = ? AND event_id = ? AND approved = 1 LIMIT 1'
                );
                $stmt->execute([$token, $round['event_id']]);
                $voter = $stmt->fetch();

                if (!$voter) {
                    throw new \RuntimeException('Invalid token.');
                }
                if ($voter['token_used']) {
                    throw new \RuntimeException('This token has already been used.');
                }

                $stmt = $pdo->prepare(
                    'UPDATE voter_lists SET token_used = 1, token_used_at = NOW() WHERE id = ?'
                );
                $stmt->execute([$voter['id']]);

                $weight = (float) $voter['weight'];
            }

            // Phase 2: anonymous_id is generated here — never derived from token or email
            $anonymousId = bin2hex(random_bytes(32));

            $stmt = $pdo->prepare(
                'INSERT INTO votes (round_id, candidate_id, category_id, value, anonymous_id)
                 VALUES (?, ?, ?, ?, ?)'
            );

            foreach ($votes as $v) {
                $stmt->execute([
                    $round['id'],
                    $v['candidate_id'],
                    $v['category_id'],
                    round($v['value'] * $weight, 4),
                    $anonymousId,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function countForRound(int $roundId): int
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT anonymous_id) FROM votes WHERE round_id = ?');
        $stmt->execute([$roundId]);
        return (int) $stmt->fetchColumn();
    }
}
