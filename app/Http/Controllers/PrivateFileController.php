<?php

namespace App\Http\Controllers;

use App\Models\ProviderDocument;
use App\Models\ProviderMember;
use App\Models\Settlement;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Private files opened from the dashboards (provider documents, settlement receipts). The link has no expiry
 * and no signature: every request is checked against the signed-in person instead. An admin needs the
 * permission for the area; a provider member only ever sees its own store's files. Everyone else gets 403.
 * Nothing here is under /storage, so the public storage folder stays what it is.
 */
class PrivateFileController extends Controller
{
    public function document(ProviderDocument $document): Response
    {
        abort_unless($this->admin(['documents.view', 'documents.manage']) || $this->member($document->provider_id, 'profile.manage'), 403);

        return $this->serve(ProviderDocument::DISK, $document->file_path);
    }

    public function receipt(Settlement $settlement): Response
    {
        abort_unless($this->admin(['settlements.view', 'settlements.manage']) || $this->member($settlement->provider_id, 'finance.view'), 403);

        return $this->serve(Settlement::RECEIPT_DISK, $settlement->receipt_path);
    }

    /** A signed, expiring link made by Storage::disk('local')->temporaryUrl(); the signature is the permission. */
    public function signed(Request $request): Response
    {
        $path = (string) $request->query('path');
        abort_if($path === '' || str_contains($path, '..'), 404);

        return $this->serve('local', $path);
    }

    /** @param  array<int, string>  $permissions */
    private function admin(array $permissions): bool
    {
        $user = auth('web')->user();
        if (! $user instanceof User || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
            return false;
        }

        return collect($permissions)->contains(fn (string $p) => $user->can($p));
    }

    private function member(int $providerId, string $permission): bool
    {
        $user = auth('provider')->user();
        if (! $user instanceof User || ! $user->canAccessPanel(Filament::getPanel('provider'))) {
            return false;
        }

        $member = ProviderMember::where('provider_id', $providerId)->where('user_id', $user->id)->first();

        return (bool) $member?->allows($permission);
    }

    private function serve(string $disk, ?string $path): Response
    {
        abort_unless($path && Storage::disk($disk)->exists($path), 404);

        $response = Storage::disk($disk)->response($path);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
