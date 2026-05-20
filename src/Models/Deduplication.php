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
     * Combined similarity score (0–100).
     * Checks prefix/substring containment first (catches "amnesty" vs
     * "amnesty international"), then falls back to similar_text + levenshtein.
     */
    public static function similarity(string $a, string $b): int
    {
        if ($a === $b) return 100;

        $short = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $long  = mb_strlen($a) <= mb_strlen($b) ? $b : $a;

        // One string is a prefix of the other → very likely duplicate
        if (str_starts_with($long, $short)) return 85;

        // One string is contained inside the other
        if (mb_strpos($long, $short) !== false) return 78;

        similar_text($a, $b, $stPct);

        $maxLen   = max(mb_strlen($a), mb_strlen($b));
        $levScore = $maxLen > 0
            ? (int) round((1 - levenshtein(mb_substr($a, 0, 255), mb_substr($b, 0, 255)) / $maxLen) * 100)
            : 100;

        return (int) round(((float) $stPct + $levScore) / 2);
    }

    /**
     * Re-checks ALL active candidates in a round against each other.
     * Use after fixing the similarity algorithm or to catch missed duplicates.
     * Returns number of new queue entries created.
     */
    public static function rescanAll(int $roundId): int
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            "SELECT * FROM candidates
             WHERE round_id = ? AND status = 'active'"
        );
        $stmt->execute([$roundId]);
        $candidates = $stmt->fetchAll();

        $added = 0;
        foreach ($candidates as $cand) {
            $before = self::queueCount($roundId);
            self::checkAndQueue($roundId, (int) $cand['category_id'], $cand['name'], (int) $cand['id']);
            if (self::queueCount($roundId) > $before) $added++;
        }
        return $added;
    }

    private static function queueCount(int $roundId): int
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dedup_queue WHERE round_id = ? AND status = 'pending'");
        $stmt->execute([$roundId]);
        return (int) $stmt->fetchColumn();
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

    /**
     * Returns pending queue entries grouped by (category_id, suggested_candidate_id).
     * Each group has a 'items' array. Entries with no match form singleton groups.
     */
    public static function getPendingQueueGrouped(int $roundId): array
    {
        $flat   = self::getPendingQueue($roundId);
        $groups = [];

        foreach ($flat as $item) {
            $key = $item['category_id'] . ':' . ($item['suggested_candidate_id'] ?? ('x' . $item['id']));
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'category_id'            => $item['category_id'],
                    'category_name'          => $item['category_name'],
                    'suggested_candidate_id' => $item['suggested_candidate_id'],
                    'suggested_name'         => $item['suggested_name'],
                    'max_score'              => $item['similarity_score'],
                    'items'                  => [],
                ];
            }
            $groups[$key]['items'][] = $item;
        }

        return array_values($groups);
    }

    /**
     * Merge a group of queue entries into one target candidate.
     * $items: array of ['queue_id' => int, 'source_candidate_id' => int|null]
     */
    public static function mergeGroup(
        array  $items,
        int    $targetCandidateId,
        int    $reviewedBy,
        string $canonicalOverride = ''
    ): void {
        $canonicalOverride = Candidate::sanitizeName($canonicalOverride);
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            if ($canonicalOverride !== '') {
                $newNorm = Candidate::normalize($canonicalOverride);
                $pdo->prepare('UPDATE candidates SET name = ?, canonical_name = ? WHERE id = ?')
                    ->execute([$canonicalOverride, $newNorm, $targetCandidateId]);
            }

            $stmt = $pdo->prepare(
                'SELECT c.canonical_name, c.category_id, er.event_id
                 FROM candidates c JOIN event_rounds er ON er.id = c.round_id
                 WHERE c.id = ? LIMIT 1'
            );
            $stmt->execute([$targetCandidateId]);
            $target = $stmt->fetch();
            if (!$target) throw new \RuntimeException('Target candidate not found.');

            foreach ($items as ['queue_id' => $queueId, 'source_candidate_id' => $sourceCandidateId]) {
                $stmt = $pdo->prepare('SELECT * FROM dedup_queue WHERE id = ? LIMIT 1');
                $stmt->execute([$queueId]);
                $entry = $stmt->fetch();
                if (!$entry) continue;

                if ($sourceCandidateId && $sourceCandidateId !== $targetCandidateId) {
                    $pdo->prepare('UPDATE votes SET candidate_id = ? WHERE candidate_id = ? AND round_id = ?')
                        ->execute([$targetCandidateId, $sourceCandidateId, $entry['round_id']]);

                    $pdo->prepare("UPDATE candidates SET status = 'merged' WHERE id = ?")
                        ->execute([$sourceCandidateId]);

                    $pdo->prepare(
                        'INSERT IGNORE INTO candidate_aliases
                         (event_id, category_id, alias, canonical_name, created_by)
                         VALUES (?, ?, ?, ?, ?)'
                    )->execute([
                        $target['event_id'],
                        $entry['category_id'],
                        $entry['normalized_input'],
                        $target['canonical_name'],
                        $reviewedBy,
                    ]);
                }

                $pdo->prepare(
                    "UPDATE dedup_queue SET status = 'merged', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?"
                )->execute([$reviewedBy, $queueId]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Mark a group of queue entries as 'kept'. */
    public static function keepGroup(array $queueIds, int $reviewedBy): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            "UPDATE dedup_queue SET status = 'kept', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?"
        );
        foreach ($queueIds as $qid) {
            $stmt->execute([$reviewedBy, (int)$qid]);
        }
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
        $canonicalOverride = Candidate::sanitizeName($canonicalOverride);
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

    /**
     * Manual merge: admin directly selects source and target without a queue entry.
     * Moves votes, marks source merged, saves alias, closes any open queue entry.
     */
    public static function manualMerge(
        int $sourceCandidateId,
        int $targetCandidateId,
        int $roundId,
        int $reviewedBy,
        string $canonicalOverride = ''
    ): void {
        $canonicalOverride = Candidate::sanitizeName($canonicalOverride);
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            // Source info
            $stmt = $pdo->prepare('SELECT canonical_name, category_id FROM candidates WHERE id = ?');
            $stmt->execute([$sourceCandidateId]);
            $source = $stmt->fetch();
            if (!$source) throw new \RuntimeException('Source candidate not found.');

            // Optional rename of target
            if ($canonicalOverride !== '') {
                $newNorm = Candidate::normalize($canonicalOverride);
                $pdo->prepare('UPDATE candidates SET name = ?, canonical_name = ? WHERE id = ?')
                    ->execute([$canonicalOverride, $newNorm, $targetCandidateId]);
            }

            // Target info (after possible rename)
            $stmt = $pdo->prepare(
                'SELECT c.canonical_name, c.category_id, er.event_id
                 FROM candidates c JOIN event_rounds er ON er.id = c.round_id
                 WHERE c.id = ? LIMIT 1'
            );
            $stmt->execute([$targetCandidateId]);
            $target = $stmt->fetch();
            if (!$target) throw new \RuntimeException('Target candidate not found.');

            // Move votes
            $pdo->prepare('UPDATE votes SET candidate_id = ? WHERE candidate_id = ? AND round_id = ?')
                ->execute([$targetCandidateId, $sourceCandidateId, $roundId]);

            // Mark source merged
            $pdo->prepare("UPDATE candidates SET status = 'merged' WHERE id = ?")
                ->execute([$sourceCandidateId]);

            // Save alias
            $pdo->prepare(
                'INSERT IGNORE INTO candidate_aliases
                 (event_id, category_id, alias, canonical_name, created_by)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $target['event_id'],
                $source['category_id'],
                $source['canonical_name'],
                $target['canonical_name'],
                $reviewedBy,
            ]);

            // Close any open dedup queue entry for this source
            $pdo->prepare(
                "UPDATE dedup_queue
                 SET status = 'merged', reviewed_by = ?, reviewed_at = NOW()
                 WHERE round_id = ? AND normalized_input = ? AND status = 'pending'"
            )->execute([$reviewedBy, $roundId, $source['canonical_name']]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Merge a free-form list of candidate IDs chosen by the admin (no queue entry required).
     * The target is the candidate with the most votes; ties broken by lowest ID.
     */
    public static function mergeManual(array $candidateIds, int $reviewedBy, string $canonicalOverride = ''): void
    {
        $canonicalOverride = Candidate::sanitizeName($canonicalOverride);
        if (count($candidateIds) < 2) return;

        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            // Pick target: candidate with most votes in this round
            $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT c.id, c.round_id, c.canonical_name, c.category_id, er.event_id,
                        (SELECT COUNT(*) FROM votes v WHERE v.candidate_id = c.id AND v.round_id = c.round_id) AS vote_count
                 FROM candidates c JOIN event_rounds er ON er.id = c.round_id
                 WHERE c.id IN ($placeholders)
                 ORDER BY vote_count DESC, c.id ASC
                 LIMIT 1"
            );
            $stmt->execute($candidateIds);
            $target = $stmt->fetch();
            if (!$target) throw new \RuntimeException('Target candidate not found.');
            $targetId = (int) $target['id'];

            // Optional rename
            if ($canonicalOverride !== '') {
                $newNorm = Candidate::normalize($canonicalOverride);
                $pdo->prepare('UPDATE candidates SET name = ?, canonical_name = ? WHERE id = ?')
                    ->execute([$canonicalOverride, $newNorm, $targetId]);
                $target['canonical_name'] = $newNorm;
            }

            foreach ($candidateIds as $cid) {
                $cid = (int) $cid;
                if ($cid === $targetId) continue;

                $stmt = $pdo->prepare('SELECT canonical_name, category_id FROM candidates WHERE id = ?');
                $stmt->execute([$cid]);
                $source = $stmt->fetch();
                if (!$source) continue;

                $pdo->prepare('UPDATE votes SET candidate_id = ? WHERE candidate_id = ? AND round_id = ?')
                    ->execute([$targetId, $cid, $target['round_id']]);

                $pdo->prepare("UPDATE candidates SET status = 'merged' WHERE id = ?")
                    ->execute([$cid]);

                $pdo->prepare(
                    'INSERT IGNORE INTO candidate_aliases
                     (event_id, category_id, alias, canonical_name, created_by)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $target['event_id'],
                    $source['category_id'],
                    $source['canonical_name'],
                    $target['canonical_name'],
                    $reviewedBy,
                ]);

                // Close any open dedup queue entry for this source
                $pdo->prepare(
                    "UPDATE dedup_queue
                     SET status = 'merged', reviewed_by = ?, reviewed_at = NOW()
                     WHERE round_id = ? AND normalized_input = ? AND status = 'pending'"
                )->execute([$reviewedBy, $target['round_id'], $source['canonical_name']]);
            }

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
