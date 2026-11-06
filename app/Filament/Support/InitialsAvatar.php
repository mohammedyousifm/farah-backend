<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The dashboards' default avatar: the person's initials drawn as an inline SVG. Filament's default asks
 * ui-avatars.com, which costs an outside request on every page and sends the person's name to that site.
 */
class InitialsAvatar implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))->trim()->explode(' ')
            ->map(fn (string $part) => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $part), 0, 1))
            ->filter()->take(2)->join(' ');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#9b1bb8"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="Almarai,Tahoma,sans-serif" font-size="24" font-weight="700" fill="#fff">'
            .htmlspecialchars($initials, ENT_XML1).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
