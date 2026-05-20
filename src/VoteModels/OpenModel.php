<?php

declare(strict_types=1);

namespace Electus\VoteModels;

use Electus\Models\Candidate;
use Electus\Models\Deduplication;
use Electus\Core\Database;

class OpenModel implements VoteModelInterface
{
    public function renderForm(array $round, array $categories, array $candidatesByCategory): string
    {
        $html = '';
        foreach ($categories as $cat) {
            $html .= '<div class="e-vote-box">';
            $html .= '<div class="e-vote-category">' . htmlspecialchars($cat['name']) . '</div>';
            $html .= '<input class="uk-input" type="text" name="vote[' . $cat['id'] . ']"';
            $html .= ' placeholder="Type the candidate\'s name..."';
            $html .= ' autocomplete="off" maxlength="300">';
            $html .= '</div>';
        }
        return $html;
    }

    public function validate(array $postData, array $round, array $categories, array $candidatesByCategory): array
    {
        $errors = [];
        $hasAny = false;
        foreach ($categories as $cat) {
            $val = trim($postData['vote'][$cat['id']] ?? '');
            if ($val !== '') { $hasAny = true; break; }
        }
        if (!$hasAny) {
            $errors[] = 'Please enter at least one candidate name.';
        }
        return $errors;
    }

    public function buildVotes(array $postData, array $round, array $candidatesByCategory): array
    {
        $votes = [];
        foreach ($postData['vote'] ?? [] as $catId => $rawName) {
            $catId   = (int) $catId;
            $rawName = trim($rawName);
            if ($rawName === '') continue;

            // Find or create candidate
            $candId = self::findOrCreateCandidate($round['id'], $catId, $rawName);
            if ($candId) {
                $votes[] = ['candidate_id' => $candId, 'category_id' => $catId, 'value' => 1.0];
            }
        }
        return $votes;
    }

    private static function findOrCreateCandidate(int $roundId, int $categoryId, string $rawName): ?int
    {
        $rawName    = Candidate::sanitizeName($rawName);
        $normalized = Candidate::normalize($rawName);
        $pdo        = Database::get();

        // Check alias dictionary first
        $stmt = $pdo->prepare(
            'SELECT canonical_name FROM candidate_aliases
             WHERE event_id = (SELECT event_id FROM event_rounds WHERE id = ?)
             AND category_id = ?
             AND alias = ? LIMIT 1'
        );
        $stmt->execute([$roundId, $categoryId, $normalized]);
        $alias = $stmt->fetchColumn();
        if ($alias) {
            $normalized = $alias;
        }

        // Find existing candidate by canonical name
        $stmt = $pdo->prepare(
            "SELECT id FROM candidates
             WHERE round_id = ? AND category_id = ? AND canonical_name = ? AND status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$roundId, $categoryId, $normalized]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int) $existing;
        }

        // Create new user_input candidate and trigger dedup check
        $newId = Candidate::create($roundId, $categoryId, $rawName, 'user_input');
        Deduplication::checkAndQueue($roundId, $categoryId, $rawName, $newId);
        return $newId;
    }
}
