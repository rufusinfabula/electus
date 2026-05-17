<?php

declare(strict_types=1);

namespace Electus\VoteModels;

// Weighted voting uses the same UI as SingleModel.
// The voter's weight is applied in Vote::cast() by multiplying value × voter_lists.weight.
class WeightedModel implements VoteModelInterface
{
    public function renderForm(array $round, array $categories, array $candidatesByCategory): string
    {
        $html = '<p class="uk-text-muted uk-text-small">Select one candidate per category. Your vote carries its assigned weight.</p>';

        foreach ($categories as $cat) {
            $candidates = $candidatesByCategory[$cat['id']] ?? [];
            if (empty($candidates)) continue;

            $html .= '<div class="e-vote-box">';
            $html .= '<div class="e-vote-category">' . htmlspecialchars($cat['name']) . '</div>';
            foreach ($candidates as $c) {
                $id = 'c_' . $cat['id'] . '_' . $c['id'];
                $html .= '<label class="e-vote-option" for="' . $id . '">';
                $html .= '<input type="radio" id="' . $id . '" name="vote[' . $cat['id'] . ']" value="' . $c['id'] . '" required class="e-vote-radio">';
                $html .= '<span>' . htmlspecialchars($c['name']) . '</span>';
                $html .= '</label>';
            }
            $html .= '</div>';
        }
        return $html;
    }

    public function validate(array $postData, array $round, array $categories, array $candidatesByCategory): array
    {
        $errors = [];
        foreach ($categories as $cat) {
            if (empty($candidatesByCategory[$cat['id']])) continue;
            if (empty($postData['vote'][$cat['id']])) {
                $errors[] = 'Please select a candidate for: ' . htmlspecialchars($cat['name']);
            }
        }
        return $errors;
    }

    public function buildVotes(array $postData, array $round, array $candidatesByCategory): array
    {
        $votes = [];
        foreach ($postData['vote'] ?? [] as $catId => $candId) {
            $catId  = (int) $catId;
            $candId = (int) $candId;
            $cands  = array_column($candidatesByCategory[$catId] ?? [], null, 'id');
            if (isset($cands[$candId])) {
                $votes[] = ['candidate_id' => $candId, 'category_id' => $catId, 'value' => 1.0];
            }
        }
        return $votes;
    }
}
