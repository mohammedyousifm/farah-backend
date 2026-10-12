<?php

namespace App\Models;

use App\Models\Concerns\Localized;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $home_section_id
 * @property string|null $title_ar
 * @property string|null $title_en
 * @property string|null $media_type
 * @property string|null $media_path
 * @property string|null $media_url
 * @property string $link_type
 * @property string|null $link_value
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read HomeSection $section
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem whereHomeSectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem whereLinkType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem whereLinkValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem whereMediaPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem whereMediaType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem whereMediaUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem whereTitleAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem whereTitleEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HomeSectionItem whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
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
