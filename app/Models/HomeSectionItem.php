<?php

namespace App\Models;

use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class HomeSectionItem extends Model
{
    use Localized;

    public const LINK_TYPES = ['none', 'venue', 'category', 'sector', 'provider', 'url'];

    protected $guarded = ['id'];

    public function section(): BelongsTo
    {
        return $this->belongsTo(HomeSection::class, 'home_section_id');
    }

    public function mediaSrc(): ?string
    {
        return $this->media_path ? Storage::disk('public')->url($this->media_path) : $this->media_url;
    }
}
