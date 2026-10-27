<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfileMedia;

final class ProductPackagingPresentation
{
    public function __construct(private readonly PackagingPresetCatalog $presets) {}

    public function presets(): array
    {
        return collect($this->presets->all())->map(fn (array $preset) =>
            ['key' => $preset['key'], 'label' => $preset['label'], 'summary' => $preset['summary']]
        )->values()->all();
    }

    public function serialize(ProductPackagingProfile $profile): array
    {
        $media=$profile->relationLoaded('media') ? $profile->media : $profile->media()->get();
        $one=fn(string $kind)=>$media->firstWhere('kind',$kind);
        $url=fn(?ProductPackagingProfileMedia $m)=>$m ? route('products.packaging.media',$m) : null;
        $preset=$this->presets->get($profile->preset_key);
        return [
            'id'=>$profile->id,
            'preset_key'=>$profile->preset_key,
            'method'=>$preset['label'] ?? $profile->custom_method ?? '',
            'scenario'=>$profile->scenario,
            'version'=>$profile->version,
            'units_per_carton'=>$profile->units_per_carton,
            'inherits_global_template'=>$profile->preset_key !== null,
            'default_image'=>($m=$one('default')) ? ['id'=>$m->id,'name'=>$m->original_name,'url'=>$url($m)] : null,
            'rendered_image'=>($m=$one('rendered')) ? ['id'=>$m->id,'name'=>$m->original_name,'url'=>$url($m)] : null,
            'examples'=>$media->where('kind','example')->values()->map(fn($m)=>['id'=>$m->id,'name'=>$m->original_name,'url'=>$url($m)])->all(),
        ];
    }
}
