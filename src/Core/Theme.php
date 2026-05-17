<?php

declare(strict_types=1);

namespace Electus\Core;

class Theme
{
    // System-default preset key (can be overridden in config.php under 'theme' => ['default' => '...'])
    public const DEFAULT_PRESET = 'saas';

    public const PALETTES = [
        'institutional' => [
            'label'     => 'Istituzionale moderna',
            'primary'   => '#1D4ED8',
            'secondary' => '#475569',
            'accent'    => '#F59E0B',
            'bg'        => '#F8FAFC',
            'text'      => '#0F172A',
            'dark'      => false,
        ],
        'dark' => [
            'label'     => 'Minimal scura',
            'primary'   => '#2563EB',
            'secondary' => '#64748B',
            'accent'    => '#22C55E',
            'bg'        => '#0F172A',
            'text'      => '#E2E8F0',
            'dark'      => true,
        ],
        'civic' => [
            'label'     => 'Civica / partecipativa',
            'primary'   => '#006D77',
            'secondary' => '#83C5BE',
            'accent'    => '#FFB703',
            'bg'        => '#EDF6F9',
            'text'      => '#1D3557',
            'dark'      => false,
        ],
        'ministero' => [
            'label'     => 'Ministero / Comune',
            'primary'   => '#003366',
            'secondary' => '#5B6770',
            'accent'    => '#B08D57',
            'bg'        => '#F7F7F2',
            'text'      => '#1F2933',
            'dark'      => false,
        ],
        'saas' => [
            'label'     => 'SaaS moderno',
            'primary'   => '#4F46E5',
            'secondary' => '#06B6D4',
            'accent'    => '#A855F7',
            'bg'        => '#F9FAFB',
            'text'      => '#111827',
            'dark'      => false,
        ],
        'accessible' => [
            'label'     => 'Accessibile WCAG AA/AAA',
            'primary'   => '#003B73',
            'secondary' => '#4B5563',
            'accent'    => '#B45309',
            'bg'        => '#FFFFFF',
            'text'      => '#111827',
            'dark'      => false,
        ],
        'tv' => [
            'label'     => 'TV / risultati live',
            'primary'   => '#0B1F3A',
            'secondary' => '#E11D48',
            'accent'    => '#FACC15',
            'bg'        => '#06111F',
            'text'      => '#F8FAFC',
            'dark'      => true,
        ],
        'uikit' => [
            'label'     => 'UIkit nativa',
            'primary'   => '#1E87F0',
            'secondary' => '#222222',
            'accent'    => '#32D296',
            'bg'        => '#FFFFFF',
            'text'      => '#222222',
            'dark'      => false,
        ],
        'auto' => [
            'label'     => 'Automatica (dark/light OS)',
            'primary'   => '#155EEF',
            'secondary' => '#667085',
            'accent'    => '#DC6803',
            'bg'        => '#FFFFFF',
            'text'      => '#101828',
            'dark_primary'   => '#84ADFF',
            'dark_secondary' => '#98A2B3',
            'dark_accent'    => '#FDB022',
            'dark_bg'        => '#101828',
            'dark_text'      => '#F2F4F7',
            'dark'      => false,
        ],
    ];

    /**
     * Resolve the effective palette for an event.
     * Priority: event custom colors > event preset > system config default > self::DEFAULT_PRESET
     */
    public static function forEvent(array $event, array $config = []): array
    {
        $systemDefault = $config['theme']['default'] ?? self::DEFAULT_PRESET;

        // Start from preset
        $presetKey = $event['theme_preset'] ?? $systemDefault;
        $palette   = self::PALETTES[$presetKey] ?? self::PALETTES[self::DEFAULT_PRESET];

        // Merge custom color overrides stored as JSON on the event
        if (!empty($event['theme_colors'])) {
            $custom  = is_array($event['theme_colors'])
                ? $event['theme_colors']
                : (json_decode($event['theme_colors'], true) ?? []);
            foreach (['primary','secondary','accent','bg','text'] as $key) {
                if (!empty($custom[$key])) $palette[$key] = $custom[$key];
            }
        }

        return $palette;
    }

    /**
     * Output a <style> block with the resolved CSS custom properties.
     * Call this inside <head> on public pages.
     */
    public static function cssBlock(array $palette): string
    {
        $lines = [
            '--e-primary: '   . htmlspecialchars($palette['primary'])   . ';',
            '--e-secondary: ' . htmlspecialchars($palette['secondary']) . ';',
            '--e-accent: '    . htmlspecialchars($palette['accent'])    . ';',
            '--e-bg: '        . htmlspecialchars($palette['bg'])        . ';',
            '--e-text: '      . htmlspecialchars($palette['text'])      . ';',
        ];
        $css = ':root{' . implode('', $lines) . '}';

        // auto preset: add dark media query
        if (!empty($palette['dark_bg'])) {
            $darkLines = [
                '--e-primary: '   . htmlspecialchars($palette['dark_primary'])   . ';',
                '--e-secondary: ' . htmlspecialchars($palette['dark_secondary']) . ';',
                '--e-accent: '    . htmlspecialchars($palette['dark_accent'])    . ';',
                '--e-bg: '        . htmlspecialchars($palette['dark_bg'])        . ';',
                '--e-text: '      . htmlspecialchars($palette['dark_text'])      . ';',
            ];
            $css .= '@media(prefers-color-scheme:dark){:root{' . implode('', $darkLines) . '}}';
        }

        return '<style>' . $css . '</style>';
    }

    public static function presetList(): array
    {
        return array_map(fn($k, $v) => ['key' => $k] + $v, array_keys(self::PALETTES), self::PALETTES);
    }
}
