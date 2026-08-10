<?php

use App\Models\GeneralSetting;
use Illuminate\Support\Facades\Storage;

if (! function_exists('gs')) {
    /**
     * Read the singleton general-settings row (or one of its columns).
     */
    function gs(?string $key = null): mixed
    {
        try {
            $settings = GeneralSetting::current();
        } catch (\Throwable) {
            return $key ? null : null;
        }

        return $key ? $settings->{$key} : $settings;
    }
}

if (! function_exists('currencyText')) {
    function currencyText(): string
    {
        return gs('cur_text') ?: config('vipuri.currency.text');
    }
}

if (! function_exists('currencySymbol')) {
    function currencySymbol(): string
    {
        return gs('cur_sym') ?: config('vipuri.currency.symbol');
    }
}

if (! function_exists('showAmount')) {
    /**
     * Format a monetary value for display. VIPURI trades in TZS, which is not
     * used with sub-units in practice, so amounts default to whole shillings
     * with thousands separators — e.g. "TZS 1,500,000".
     */
    function showAmount(
        float|int|string|null $amount,
        ?int $decimals = null,
        bool $withCurrency = true,
    ): string {
        $decimals ??= (int) config('vipuri.currency.decimals', 0);
        $formatted = number_format((float) $amount, $decimals);

        if (! $withCurrency) {
            return $formatted;
        }

        return currencyText() . ' ' . $formatted;
    }
}

if (! function_exists('getAmount')) {
    /** Normalise a raw amount for storage/JSON output. */
    function getAmount(float|int|string|null $amount, int $decimals = 2): float
    {
        return (float) number_format((float) $amount, $decimals, '.', '');
    }
}

if (! function_exists('getFilePath')) {
    function getFilePath(string $key): string
    {
        return config("vipuri.file_path.$key.path", 'assets/images/default');
    }
}

if (! function_exists('getFileSize')) {
    function getFileSize(string $key): ?string
    {
        return config("vipuri.file_path.$key.size");
    }
}

if (! function_exists('getThumbSize')) {
    function getThumbSize(string $key): ?string
    {
        return config("vipuri.file_path.$key.thumb");
    }
}

if (! function_exists('fileUrl')) {
    /**
     * Absolute URL for a stored file, or null when nothing is stored. The
     * frontend renders its own placeholder when this is null, which mirrors
     * the source system's placeholder behaviour.
     */
    function fileUrl(string $key, ?string $filename, bool $thumb = false): ?string
    {
        if (! $filename) {
            return null;
        }

        $path = getFilePath($key) . '/' . ($thumb ? 'thumb_' : '') . $filename;

        if (! Storage::disk('public')->exists($path)) {
            // Fall back to the full-size image when a thumbnail is missing.
            $path = getFilePath($key) . '/' . $filename;

            if (! Storage::disk('public')->exists($path)) {
                return null;
            }
        }

        return Storage::disk('public')->url($path);
    }
}

if (! function_exists('getPaginate')) {
    function getPaginate(?int $default = null): int
    {
        $perPage = (int) request()->integer('per_page', 0);

        if ($perPage > 0) {
            return min($perPage, 100);
        }

        return $default ?: ((int) gs('paginate_number') ?: 12);
    }
}

if (! function_exists('responseSuccess')) {
    function responseSuccess(string $remark, array|string $message, array $data = [], int $code = 200)
    {
        return response()->json([
            'remark' => $remark,
            'status' => 'success',
            'message' => ['success' => (array) $message],
            'data' => $data,
        ], $code);
    }
}

if (! function_exists('responseError')) {
    function responseError(string $remark, array|string $message, array $data = [], int $code = 422)
    {
        return response()->json([
            'remark' => $remark,
            'status' => 'error',
            'message' => ['error' => (array) $message],
            'data' => $data,
        ], $code);
    }
}

if (! function_exists('getTrx')) {
    function getTrx(int $length = 12): string
    {
        return strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, $length));
    }
}

if (! function_exists('titleToKey')) {
    function titleToKey(string $text): string
    {
        return strtolower(str_replace(' ', '_', trim($text)));
    }
}

if (! function_exists('keyToTitle')) {
    function keyToTitle(string $text): string
    {
        return ucfirst(str_replace('_', ' ', $text));
    }
}

if (! function_exists('diffForHumans')) {
    function diffForHumans(?string $date): ?string
    {
        return $date ? \Carbon\Carbon::parse($date)->diffForHumans() : null;
    }
}
