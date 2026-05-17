<?php

declare(strict_types=1);

namespace Electus\VoteModels;

class VoteModelFactory
{
    public static function make(string $model): VoteModelInterface
    {
        return match ($model) {
            'open'         => new OpenModel(),
            'single'       => new SingleModel(),
            'multiple'     => new MultipleModel(),
            'borda'        => new BordaModel(),
            'proportional' => new ProportionalModel(),
            'weighted'     => new WeightedModel(),
            default        => throw new \InvalidArgumentException('Unknown vote model: ' . $model),
        };
    }
}
