<?php

declare(strict_types=1);

namespace Electus\VoteModels;

class ProportionalModel implements VoteModelInterface
{
    public function renderForm(array $round, array $categories, array $candidatesByCategory): string
    {
        $html = '<p class="uk-text-muted uk-text-small">Select as many candidates as you like. Seats will be allocated proportionally to the votes received.</p>';

        foreach ($categories as $cat) {
            $candidates = $candidatesByCategory[$cat['id']] ?? [];
            if (empty($candidates)) continue;

            $html .= '<div class="e-vote-box">';
            $html .= '<div class="e-vote-category">' . htmlspecialchars($cat['name']) . '</div>';
            foreach ($candidates as $c) {
                $id = 'c_' . $cat['id'] . '_' . $c['id'];
                $html .= '<label class="e-vote-option" for="' . $id . '">';
                $html .= '<input type="checkbox" id="' . $id . '" name="vote[' . $cat['id'] . '][]" value="' . $c['id'] . '">';
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
        $hasAny = false;
        foreach ($categories as $cat) {
            $selected = $postData['vote'][$cat['id']] ?? [];
            if (!empty($selected)) { $hasAny = true; break; }
        }
        if (!$hasAny) {
            $errors[] = 'Please select at least one candidate.';
        }
        return $errors;
    }

    public function buildVotes(array $postData, array $round, array $candidatesByCategory): array
    {
        $votes = [];
        foreach ($postData['vote'] ?? [] as $catId => $candIds) {
            $catId    = (int) $catId;
            $candIds  = array_map('intval', (array) $candIds);
            $validIds = array_column($candidatesByCategory[$catId] ?? [], 'id');
            foreach ($candIds as $candId) {
                if (in_array($candId, $validIds, true)) {
                    $votes[] = ['candidate_id' => $candId, 'category_id' => $catId, 'value' => 1.0];
                }
            }
        }
        return $votes;
    }
}
