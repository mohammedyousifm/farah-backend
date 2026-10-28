<?php

namespace App\Filament\Support;

/** Accepted file types for dashboard uploads. */
class Upload
{
    /** Raster images only. Filament's image() accepts image/*, which lets SVG (a script carrier) onto the public disk. */
    public const IMAGES = ['image/jpeg', 'image/png', 'image/webp'];
}
