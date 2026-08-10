<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'translations' => 'array',
        ];
    }

    /**
     * The strings the storefront asks for.
     *
     * Keys are the English source text, exactly as `@lang()` used them in the
     * original, so an untranslated key falls back to reading correctly rather
     * than showing a dotted identifier.
     */
    public function strings(): array
    {
        return array_filter(
            $this->translations ?? [],
            fn ($value) => is_string($value) && trim($value) !== '',
        );
    }

    public static function default(): ?self
    {
        return static::where('is_default', true)->first() ?? static::orderBy('id')->first();
    }
}
