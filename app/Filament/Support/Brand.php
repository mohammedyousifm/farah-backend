<?php

namespace App\Filament\Support;

/**
 * Brand colours for both Filament panels.
 *
 * The primary palette is hand-tuned around the logo magenta (#B21FD1 at 500). Color::hex()
 * normalises lightness, which made the generated 600 shade too light for white text, so
 * Filament fell back to pale 400 buttons with dark text. Here 600 (#9A17B5) passes WCAG AA
 * with white text, so primary buttons render solid.
 */
class Brand
{
    public const PRIMARY = [
        50 => 'oklch(0.974 0.017 320.058)',  // #fcf3fe
        100 => 'oklch(0.941 0.038 319.167)', // #f7e4fc
        200 => 'oklch(0.881 0.077 319.916)', // #efc8f8
        300 => 'oklch(0.789 0.137 320.746)', // #e29cf0
        400 => 'oklch(0.678 0.208 320.866)', // #cf63e4
        500 => 'oklch(0.566 0.255 319.373)', // #b21fd1 (logo)
        600 => 'oklch(0.508 0.231 319.471)', // #9a17b5
        700 => 'oklch(0.441 0.197 319.973)', // #7f1494
        800 => 'oklch(0.385 0.165 320.424)', // #681478
        900 => 'oklch(0.342 0.138 320.326)', // #561563
        950 => 'oklch(0.25 0.113 321.146)',  // #380341
    ];

    /** Chart series colours (logo gradient order: magenta, amber, pink, violet, blue, ...). */
    public const CHART = ['#B21FD1', '#F9B233', '#E6007E', '#7B2FF7', '#3D8BFF', '#10B981', '#F97316', '#64748B'];
}
