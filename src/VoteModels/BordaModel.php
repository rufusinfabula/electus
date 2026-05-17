<?php

declare(strict_types=1);

namespace Electus\VoteModels;

class BordaModel implements VoteModelInterface
{
    public function renderForm(array $round, array $categories, array $candidatesByCategory): string
    {
        $cfg    = $round['config'] ?? [];
        $mode   = $cfg['borda_mode'] ?? 'fixed';
        $html   = '';

        if ($mode === 'fixed') {
            $scale  = array_map('intval', explode(',', $cfg['borda_scale'] ?? '3,2,1'));
            $html  .= '<p class="uk-text-muted uk-text-small">Assign points to candidates: '
                    . implode(', ', $scale) . '. Each value can only be used once per category.</p>';
        } else {
            $budget = (int) ($cfg['borda_budget'] ?? 100);
            $html  .= '<p class="uk-text-muted uk-text-small">Distribute <strong>' . $budget . ' points</strong> among candidates as you prefer.</p>';
        }

        foreach ($categories as $cat) {
            $candidates = $candidatesByCategory[$cat['id']] ?? [];
            if (empty($candidates)) continue;

            $html .= '<div class="e-vote-box">';
            $html .= '<div class="e-vote-category">' . htmlspecialchars($cat['name']) . '</div>';

            foreach ($candidates as $c) {
                $html .= '<div class="uk-flex uk-flex-middle e-borda-row">';
                $html .= '<span class="uk-width-expand">' . htmlspecialchars($c['name']) . '</span>';

                if ($mode === 'fixed') {
                    $html .= '<select name="vote[' . $cat['id'] . '][' . $c['id'] . ']" class="uk-select e-borda-select" style="width:100px">';
                    $html .= '<option value="">—</option>';
                    foreach ($scale as $pts) {
                        $html .= '<option value="' . $pts . '">' . $pts . ' pts</option>';
                    }
                    $html .= '</select>';
                } else {
                    $html .= '<input type="number" name="vote[' . $cat['id'] . '][' . $c['id'] . ']"';
                    $html .= ' class="uk-input e-borda-input" style="width:100px" min="0" value="0">';
                }

                $html .= '</div>';
            }
            $html .= '</div>';
        }
        return $html;
    }

    public function validate(array $postData, array $round, array $categories, array $candidatesByCategory): array
    {
        $errors = [];
        $cfg    = $round['config'] ?? [];
        $mode   = $cfg['borda_mode'] ?? 'fixed';

        foreach ($categories as $cat) {
            $candidates = $candidatesByCategory[$cat['id']] ?? [];
            if (empty($candidates)) continue;

            $catVotes = $postData['vote'][$cat['id']] ?? [];
            $values   = array_filter(array_map('intval', $catVotes), fn($v) => $v > 0);

            if ($mode === 'fixed') {
                $scale = array_map('intval', explode(',', $cfg['borda_scale'] ?? '3,2,1'));
                $assigned = array_values($values);
                // Check for duplicate point assignments
                if (count($assigned) !== count(array_unique($assigned))) {
                    $errors[] = 'Duplicate point values in: ' . htmlspecialchars($cat['name']);
                }
                // Check values are from the allowed scale
                foreach ($assigned as $v) {
                    if (!in_array($v, $scale, true)) {
                        $errors[] = 'Invalid point value in: ' . htmlspecialchars($cat['name']);
                        break;
                    }
                }
            } else {
                $budget = (int) ($cfg['borda_budget'] ?? 100);
                $total  = array_sum($values);
                if ($total > $budget) {
                    $errors[] = 'Point budget exceeded in: ' . htmlspecialchars($cat['name']) . ' (max ' . $budget . ')';
                }
            }

            $maxCandidates = (int) ($cfg['max_candidates'] ?? 0);
            if ($maxCandidates > 0 && count($values) > $maxCandidates) {
                $errors[] = 'Too many candidates ranked in: ' . htmlspecialchars($cat['name']);
            }
        }
        return $errors;
    }

    public function buildVotes(array $postData, array $round, array $candidatesByCategory): array
    {
        $votes = [];
        foreach ($postData['vote'] ?? [] as $catId => $candPoints) {
            $catId    = (int) $catId;
            $validIds = array_column($candidatesByCategory[$catId] ?? [], 'id');
            foreach ((array) $candPoints as $candId => $points) {
                $candId = (int) $candId;
                $points = (float) $points;
                if ($points > 0 && in_array($candId, $validIds, true)) {
                    $votes[] = ['candidate_id' => $candId, 'category_id' => $catId, 'value' => $points];
                }
            }
        }
        return $votes;
    }
}
