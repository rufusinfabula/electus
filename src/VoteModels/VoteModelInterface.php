<?php

declare(strict_types=1);

namespace Electus\VoteModels;

interface VoteModelInterface
{
    /**
     * Returns the HTML form fields for this voting model.
     * The form tag and submit button are rendered by cast.php.
     */
    public function renderForm(array $round, array $categories, array $candidatesByCategory): string;

    /**
     * Validates POST data. Returns array of error strings (empty = valid).
     */
    public function validate(array $postData, array $round, array $categories, array $candidatesByCategory): array;

    /**
     * Builds the array of vote records from POST data.
     * Returns: [ ['candidate_id' => int, 'category_id' => int, 'value' => float], ... ]
     */
    public function buildVotes(array $postData, array $round, array $candidatesByCategory): array;
}
