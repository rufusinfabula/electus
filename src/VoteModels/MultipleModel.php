<?php

declare(strict_types=1);

namespace Electus\VoteModels;

class MultipleModel implements VoteModelInterface
{
    public function renderForm(array $round, array $categories, array $candidatesByCategory): string
    {
        $max  = (int) ($round['config']['max_choices'] ?? 3);
        $html = '<p class="uk-text-muted uk-text-small">Select up to <strong>' . $max . '</strong> candidates per category.</p>';

        foreach ($categories as $cat) {
            $candidates = $candidatesByCategory[$cat['id']] ?? [];
            if (empty($candidates)) continue;

            $html .= '<div class="e-vote-box" data-max="' . $max . '">';
            $html .= '<div class="e-vote-category">' . htmlspecialchars($cat['name']) . '</div>';
            foreach ($candidates as $c) {
                $id = 'c_' . $cat['id'] . '_' . $c['id'];
                $html .= '<label class="e-vote-option" for="' . $id . '">';
                $html .= '<input type="checkbox" id="' . $id . '" name="vote[' . $cat['id'] . '][]" value="' . $c['id'] . '" class="e-choice-check">';
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
        $max    = (int) ($round['config']['max_choices'] ?? 3);

        foreach ($categories as $cat) {
            if (empty($candidatesByCategory[$cat['id']])) continue;
            $selected = $postData['vote'][$cat['id']] ?? [];
            if (!is_array($selected)) $selected = [];
            if (count($selected) > $max) {
                $errors[] = 'Too many choices for: ' . htmlspecialchars($cat['name']) . ' (max ' . $max . ')';
            }
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
