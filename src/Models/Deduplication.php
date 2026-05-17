<?php

declare(strict_types=1);

namespace Electus\Models;

use Electus\Core\Database;

class Deduplication
{
    // Minimum similarity % to flag a candidate as a potential duplicate
    private const THRESHOLD = 65;

    /**
     * Called after a new user_input candidate is created in an open round.
     * Computes similarity against all existing active candidates in the same
     * round+category and adds a dedup_queue entry if a match is found.
     */
    public static function checkAndQueue(int $roundId, int $categoryId, string $rawInput, int $newCandidateId): void
    {
        $normalized = Candidate::normalize($rawInput);
        $pdo        = Database::get();

        $stmt = $pdo->prepare(
            "SELECT * FROM candidates
             WHERE round_id = ? AND category_id = ? AND id != ? AND status = 'active'"
        );
        $stmt->execute([$roundId, $categoryId, $newCandidateId]);
        $existing = $stmt->fetchAll();

        $bestScore = 0;
        $bestMatch = null;

        foreach ($existing as $cand) {
            $score = self::similarity($normalized, $cand['canonical_name']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $cand;
            }
        }

        if ($bestScore >= self::THRESHOLD && $bestMatch) {
            // Avoid duplicate queue entries for the same normalized input
            $stmt = $pdo->prepare(
                'SELECT id FROM dedup_queue WHERE round_id = ? AND category_id = ? AND normalized_input = ? LIMIT 1'
            );
            $stmt->execute([$roundId, $categoryId, $normalized]);

            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare(
                    'INSERT INTO dedup_queue
                     (round_id, category_id, raw_input, normalized_input, suggested_candidate_id, similarity_score)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$roundId, $categoryId, $rawInput, $normalized, $bestMatch['id'], $bestScore]);
            }
        }
    }

    /**
     * Combined similarity score (0–100) using similar_text + normalised levenshtein.
     */
    public static function similarity(string $a, string $b): int
    {
        if ($a === $b) return 100;

        similar_text($a, $b, $stPct);

        $maxLen = max(mb_strlen($a), mb_strlen($b));
        $levScore = $maxLen > 0
            ? (int) round((1 - levenshtein(mb_substr($a, 0, 255), mb_substr($b, 0, 255)) / $maxLen) * 100)
            : 100;

        return (int) round(((float) $stPct + $levScore) / 2);
    }

    /**
     * Returns all pending queue entries for a round, enriched with candidate
     * and category names, sorted by category then score desc.
     */
    public static function getPendingQueue(int $roundId): array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            "SELECT dq.*,
                    nc.id   AS new_cand_id,
                    nc.name AS new_cand_name,
                    sc.name AS suggested_name,
                    cat.name AS category_name
             FROM dedup_queue dq
             LEFT JOIN candidates nc ON nc.round_id = dq.round_id
                                     AND nc.category_id = dq.category_id
                                     AND nc.canonical_name = dq.normalized_input
                                     AND nc.status IN ('active','merged')
             LEFT JOIN candidates sc ON sc.id = dq.suggested_candidate_id
             LEFT JOIN categories cat ON cat.id = dq.category_id
             WHERE dq.round_id = ? AND dq.status = 'pending'
             ORDER BY cat.sort_order, dq.similarity_score DESC"
        );
        $stmt->execute([$roundId]);
        return $stmt->fetchAll();
    }

    public static function getReviewedCount(int $roundId): int
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM dedup_queue WHERE round_id = ? AND status != 'pending'"
        );
        $stmt->execute([$roundId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Merge: move all votes from $sourceCandidateId to $targetCandidateId,
     * mark source as merged, save alias for future auto-resolution.
     */
    public static function merge(
        int $queueId,
        int $sourceCandidateId,
        int $targetCandidateId,
        int $reviewedBy,
        string $canonicalOverride = ''
    ): void {
        $pdo = Database::get();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT * FROM dedup_queue WHERE id = ? LIMIT 1');
            $stmt->execute([$queueId]);
            $entry = $stmt->fetch();

            // Optionally rename the target candidate
            if ($canonicalOverride !== '') {
                $newNorm = Candidate::normalize($canonicalOverride);
                $stmt    = $pdo->prepare('UPDATE candidates SET name = ?, canonical_name = ? WHERE id = ?');
                $stmt->execute([$canonicalOverride, $newNorm, $targetCandidateId]);
            }

            // Fetch target info (after potential rename)
            $stmt   = $pdo->prepare('SELECT canonical_name, category_id, event_id FROM candidates c JOIN event_rounds er ON er.id = c.round_id WHERE c.id = ? LIMIT 1');
            $stmt->execute([$targetCandidateId]);
            $target = $stmt->fetch();

            // Move votes
            $stmt = $pdo->prepare(
                'UPDATE votes SET candidate_id = ? WHERE candidate_id = ? AND round_id = ?'
            );
            $stmt->execute([$targetCandidateId, $sourceCandidateId, $entry['round_id']]);

            // Mark source candidate as merged
            $stmt = $pdo->prepare("UPDATE candidates SET status = 'merged' WHERE id = ?");
            $stmt->execute([$sourceCandidateId]);

            // Save alias for future auto-resolution
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO candidate_aliases
                 (event_id, category_id, alias, canonical_name, created_by)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $target['event_id'] ?? $entry['round_id'], // fallback; join above provides it
                $entry['category_id'],
                $entry['normalized_input'],
                $target['canonical_name'],
                $reviewedBy,
            ]);

            // Update queue status
            $stmt = $pdo->prepare(
                "UPDATE dedup_queue SET status = 'merged', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$reviewedBy, $queueId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function keep(int $queueId, int $reviewedBy): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            "UPDATE dedup_queue SET status = 'kept', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$reviewedBy, $queueId]);
    }

    public static function exclude(int $queueId, int $candidateId, int $reviewedBy): void
    {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE candidates SET status = 'excluded' WHERE id = ?");
            $stmt->execute([$candidateId]);

            $stmt = $pdo->prepare(
                "UPDATE dedup_queue SET status = 'excluded', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$reviewedBy, $queueId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function getAliasDictionary(int $eventId): array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT ca.*, cat.name AS category_name, u.name AS created_by_name
             FROM candidate_aliases ca
             JOIN categories cat ON cat.id = ca.category_id
             LEFT JOIN users u ON u.id = ca.created_by
             WHERE ca.event_id = ?
             ORDER BY cat.sort_order, ca.canonical_name, ca.alias'
        );
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }

    public static function deleteAlias(int $aliasId): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare('DELETE FROM candidate_aliases WHERE id = ?');
        $stmt->execute([$aliasId]);
    }
}
