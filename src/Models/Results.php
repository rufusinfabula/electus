<?php

declare(strict_types=1);

namespace Electus\Models;

use Electus\Core\Database;
use PDO;

class Results
{
    /**
     * Compute and snapshot results for a round.
     * Replaces any previously computed snapshot.
     * Works for all 6 vote models — SUM(value) is the universal metric
     * because the model-specific value (points, weight, seat-share) is baked
     * in at cast time.
     */
    public static function compute(int $roundId): void
    {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM results WHERE round_id = ?')->execute([$roundId]);

            $stmt = $pdo->prepare(
                'INSERT INTO results (round_id, category_id, candidate_id, total_votes, total_points, rank)
                 SELECT
                     v.round_id,
                     v.category_id,
                     v.candidate_id,
                     COUNT(*)                        AS total_votes,
                     ROUND(SUM(v.value))             AS total_points,
                     RANK() OVER (
                         PARTITION BY v.category_id
                         ORDER BY SUM(v.value) DESC
                     )                               AS `rank`
                 FROM votes v
                 JOIN candidates c ON c.id = v.candidate_id AND c.status = ?
                 WHERE v.round_id = ?
                 GROUP BY v.round_id, v.category_id, v.candidate_id'
            );
            $stmt->execute(['active', $roundId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Returns computed results for a round, grouped by category.
     * [category_id => [row, ...]]
     */
    public static function forRound(int $roundId): array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT r.*, c.name AS candidate_name, cat.name AS category_name, cat.sort_order
             FROM results r
             JOIN candidates c   ON c.id  = r.candidate_id
             JOIN categories cat ON cat.id = r.category_id
             WHERE r.round_id = ?
             ORDER BY cat.sort_order, cat.id, r.rank, c.name'
        );
        $stmt->execute([$roundId]);
        $rows = $stmt->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['category_id']][] = $row;
        }
        return $grouped;
    }

    /**
     * Admin validates the votes for a round.
     * This is a prerequisite for publishing results.
     */
    public static function validate(int $roundId, int $adminId): void
    {
        $pdo = Database::get();
        $pdo->prepare(
            'UPDATE event_rounds
             SET votes_validated = 1, validated_by = ?, validated_at = NOW()
             WHERE id = ?'
        )->execute([$adminId, $roundId]);
    }

    public static function unvalidate(int $roundId): void
    {
        $pdo = Database::get();
        $pdo->prepare(
            'UPDATE event_rounds
             SET votes_validated = 0, validated_by = NULL, validated_at = NULL,
                 results_released = 0
             WHERE id = ?'
        )->execute([$roundId]);
    }

    public static function publish(int $roundId): void
    {
        $pdo = Database::get();
        $pdo->prepare(
            'UPDATE event_rounds SET results_released = 1 WHERE id = ?'
        )->execute([$roundId]);
    }

    public static function unpublish(int $roundId): void
    {
        $pdo = Database::get();
        $pdo->prepare(
            'UPDATE event_rounds SET results_released = 0 WHERE id = ?'
        )->execute([$roundId]);
    }

    /** Total distinct voters for a round */
    public static function voterCount(int $roundId): int
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT anonymous_id) FROM votes WHERE round_id = ?'
        );
        $stmt->execute([$roundId]);
        return (int) $stmt->fetchColumn();
    }

    /** Total individual vote rows for a round */
    public static function voteRowCount(int $roundId): int
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE round_id = ?');
        $stmt->execute([$roundId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Generates a flat array suitable for CSV export.
     */
    public static function toCsvRows(int $roundId): array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT cat.name AS category, c.name AS candidate,
                    r.total_votes, r.total_points, r.rank, r.computed_at
             FROM results r
             JOIN candidates c   ON c.id  = r.candidate_id
             JOIN categories cat ON cat.id = r.category_id
             WHERE r.round_id = ?
             ORDER BY cat.sort_order, cat.id, r.rank'
        );
        $stmt->execute([$roundId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Promotes the top N candidates from a round to the next round,
     * setting the next round's candidates' source = 'promoted'.
     * Requires promotion_confirmed to be 0 (runs once).
     */
    public static function promote(int $fromRoundId, int $toRoundId, int $topN): void
    {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            // Check not already promoted
            $stmt = $pdo->prepare('SELECT promotion_confirmed FROM event_rounds WHERE id = ?');
            $stmt->execute([$fromRoundId]);
            if ((int) $stmt->fetchColumn() === 1) {
                throw new \RuntimeException('Promotion already confirmed for this round.');
            }

            // Find top N candidates per category
            $stmt = $pdo->prepare(
                'SELECT r.candidate_id, r.category_id
                 FROM results r
                 WHERE r.round_id = ? AND r.rank <= ?
                 ORDER BY r.category_id, r.rank'
            );
            $stmt->execute([$fromRoundId, $topN]);
            $topCandidates = $stmt->fetchAll();

            if (empty($topCandidates)) {
                throw new \RuntimeException('No results computed — run Compute first.');
            }

            // Fetch source candidate data
            $ins = $pdo->prepare(
                "INSERT INTO candidates
                 (round_id, category_id, name, canonical_name, source, status)
                 SELECT ?, category_id, name, canonical_name, 'promoted', 'active'
                 FROM candidates WHERE id = ?"
            );
            foreach ($topCandidates as $row) {
                $ins->execute([$toRoundId, $row['candidate_id']]);
            }

            $pdo->prepare(
                'UPDATE event_rounds SET promotion_confirmed = 1 WHERE id = ?'
            )->execute([$fromRoundId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Returns validator user row or null */
    public static function validatorInfo(int $roundId): ?array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT u.name, er.validated_at
             FROM event_rounds er
             LEFT JOIN users u ON u.id = er.validated_by
             WHERE er.id = ? LIMIT 1'
        );
        $stmt->execute([$roundId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
