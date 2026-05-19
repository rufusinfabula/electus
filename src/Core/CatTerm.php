<?php

declare(strict_types=1);

namespace Electus\Core;

class CatTerm
{
    const DEFAULT_PRESET = 'scelta';

    const PRESETS = [
        'scelta'    => ['it' => ['s' => 'Scelta',    'p' => 'Scelte'],    'en' => ['s' => 'Option',    'p' => 'Options'],    'fr' => ['s' => 'Choix',      'p' => 'Choix']],
        'categoria' => ['it' => ['s' => 'Categoria', 'p' => 'Categorie'], 'en' => ['s' => 'Category',  'p' => 'Categories'], 'fr' => ['s' => 'Catégorie',  'p' => 'Catégories']],
        'carica'    => ['it' => ['s' => 'Carica',    'p' => 'Cariche'],   'en' => ['s' => 'Position',  'p' => 'Positions'],  'fr' => ['s' => 'Poste',      'p' => 'Postes']],
        'seggio'    => ['it' => ['s' => 'Seggio',    'p' => 'Seggi'],     'en' => ['s' => 'Seat',      'p' => 'Seats'],      'fr' => ['s' => 'Siège',      'p' => 'Sièges']],
        'posto'     => ['it' => ['s' => 'Posto',     'p' => 'Posti'],     'en' => ['s' => 'Position',  'p' => 'Positions'],  'fr' => ['s' => 'Place',      'p' => 'Places']],
        'premio'    => ['it' => ['s' => 'Premio',    'p' => 'Premi'],     'en' => ['s' => 'Award',     'p' => 'Awards'],     'fr' => ['s' => 'Prix',       'p' => 'Prix']],
        'ruolo'     => ['it' => ['s' => 'Ruolo',     'p' => 'Ruoli'],     'en' => ['s' => 'Role',      'p' => 'Roles'],      'fr' => ['s' => 'Rôle',       'p' => 'Rôles']],
        'proposta'  => ['it' => ['s' => 'Proposta',  'p' => 'Proposte'],  'en' => ['s' => 'Proposal',  'p' => 'Proposals'],  'fr' => ['s' => 'Proposition','p' => 'Propositions']],
        'incarico'  => ['it' => ['s' => 'Incarico',  'p' => 'Incarichi'], 'en' => ['s' => 'Assignment','p' => 'Assignments'],'fr' => ['s' => 'Mission',    'p' => 'Missions']],
    ];

    public static function label(array $event, string $form = 'p', string $lang = 'it'): string
    {
        $key    = $event['cat_term'] ?? self::DEFAULT_PRESET;
        $preset = self::PRESETS[$key] ?? self::PRESETS[self::DEFAULT_PRESET];
        $l      = $preset[$lang] ?? $preset['it'];
        return $l[$form] ?? $l['p'];
    }
}
