<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\FileManager;
use App\Services\StockPhotos\PexelsProvider;
use App\Services\StockPhotos\StockPhotoProvider;
use App\Services\StockPhotos\UnsplashProvider;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Give catalogue records real photography.
 *
 * The seeder renders branded placeholder tiles for every product and category
 * so the storefront is never full of broken images. They are real, stored
 * files — nothing is broken — but they are not photographs of parts, which is
 * what a shopper needs to see.
 *
 * This command replaces them. Every image travels the same route an admin
 * upload does — downloaded, handed to {@see FileManager} as an UploadedFile,
 * sniffed, re-encoded, resized, thumbnailed and stored on the public disk —
 * so an imported picture is indistinguishable from an uploaded one, and no
 * client ever hotlinks a third party.
 *
 * By default it only touches placeholder art. See {@see isGeneratedPlaceholder}.
 */
class ImportCatalogImages extends Command
{
    protected $signature = 'vipuri:catalog-images
        {--source=pexels : pexels, unsplash, or folder}
        {--only=all : all, products, or categories}
        {--folder= : With --source=folder, the directory of images named <slug>.jpg}
        {--force : Replace real photographs too, not just placeholder art}
        {--manifest : List the filename each product expects in the import folder, and whether it is there}
        {--dry-run : Report what would change without writing anything}
        {--limit=0 : Stop after this many records}
        {--names= : Comma-separated names to process, and only those. Use this to import exactly what a dry run proposed.}';

    protected $description = 'Import real photography for products and categories';

    /**
     * Placeholder art is flat-filled vector shapes, so a handful of distinct
     * colours. Every photograph has thousands. Sixteen is far above what the
     * generator can produce and far below anything photographic.
     */
    private const PLACEHOLDER_MAX_COLOURS = 16;

    private int $imported = 0;
    private int $skipped = 0;
    private int $failed = 0;

    /**
     * Restrict a run to these names, lower-cased. Empty means everything.
     *
     * De-duplication is per-run, so a second run sees a different set of used
     * photos and can hand a record a picture the first run withheld — which
     * means a re-run is not necessarily a no-op. Naming what was approved makes
     * the import reproduce the dry run exactly, however often it is run.
     *
     * @var list<string>
     */
    private array $allowed = [];

    /** Set by fetch() when folder mode simply had no file for that slug. */
    private bool $missingInFolder = false;

    /** The photo the provider chose, kept for the dry-run report. */
    private ?array $lastMatch = null;

    /** Rows accumulated for the dry-run table. */
    private array $report = [];

    /** Candidates already fetched, keyed by search term. One call per term. */
    private array $candidateCache = [];

    /** Photo URLs already handed out, so no two records share a picture. */
    private array $usedUrls = [];

    /** Why the last fetch found nothing, for the report. */
    private ?string $lastRejection = null;

    public function handle(FileManager $files): int
    {
        $source = (string) $this->option('source');
        $provider = $source === 'folder' ? null : $this->provider($source);

        if ($this->option('manifest')) {
            $this->manifest();

            return self::SUCCESS;
        }

        if ($source !== 'folder') {
            if (! $provider) {
                $this->error("Unknown --source={$source}. Use pexels, unsplash or folder.");

                return self::FAILURE;
            }

            if (! $provider->isConfigured()) {
                $this->error("No API key for {$provider->name()}.");
                $this->line('');
                $this->line("  Get a free key at " . ($provider->name() === 'pexels'
                    ? 'https://www.pexels.com/api/'
                    : 'https://unsplash.com/developers'));
                $this->line("  Then add to .env:  " . Str::upper($provider->name()) . "_API_KEY=your-key");
                $this->line('');
                $this->line('  Or import your own photography instead:');
                $this->line('    php artisan vipuri:catalog-images --source=folder --folder=storage/app/catalog-import');

                return self::FAILURE;
            }
        }

        if ($source === 'folder' && ! $this->option('folder')) {
            $this->error('--source=folder needs --folder=<path> holding images named after each slug.');

            return self::FAILURE;
        }

        $this->allowed = collect(explode(',', (string) $this->option('names')))
            ->map(fn ($name) => Str::lower(trim($name)))
            ->filter()
            ->all();

        if ($this->allowed) {
            $this->line('Restricted to ' . count($this->allowed) . ' named record(s).');
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing will be written.');
        }

        $only = (string) $this->option('only');

        if ($only === 'all' || $only === 'products') {
            $this->importProducts($files, $provider);
        }

        if ($only === 'all' || $only === 'categories') {
            $this->importCategories($files, $provider);
        }

        if ($this->option('dry-run')) {
            $this->printReport();
        }

        $this->newLine();

        $this->option('dry-run')
            ? $this->info("Would replace {$this->imported}, skip {$this->skipped}, keep placeholder {$this->failed}. Nothing was written.")
            // "kept placeholder" rather than "failed": a record the guard
            // refused to give a wrong picture to is a correct outcome, not an
            // error, and reporting it as a failure reads like something broke.
            : $this->info("Imported {$this->imported}, skipped {$this->skipped}, kept placeholder {$this->failed}.");

        return $this->failed > 0 && $this->imported === 0 ? self::FAILURE : self::SUCCESS;
    }

    /** One line of the dry-run table. */
    private function record(string $kind, string $name, string $term, ?array $match, string $action): void
    {
        $this->report[] = [
            'kind' => $kind,
            'name' => $name,
            'term' => $term,
            'photo' => $match['alt'] ?? '—',
            'page' => $match['page'] ?? '',
            'credit' => $match['credit'] ?? '',
            'why' => $match['why'] ?? ($this->lastRejection ?? ''),
            'action' => $action,
        ];
    }

    /** Print the dry-run table so each proposed match can actually be judged. */
    private function printReport(): void
    {
        foreach (['product', 'category'] as $kind) {
            $rows = array_values(array_filter($this->report, fn ($r) => $r['kind'] === $kind));

            if (! $rows) {
                continue;
            }

            $this->newLine();
            $this->line(str_repeat('─', 118));
            $this->line(strtoupper($kind) . 'S — ' . count($rows));
            $this->line(str_repeat('─', 118));

            $this->table(
                ['#', ucfirst($kind), 'Search term', 'Selected photo (provider description)', 'Action'],
                array_map(fn ($r, $i) => [
                    $i + 1,
                    Str::limit($r['name'], 30),
                    Str::limit($r['term'], 24),
                    Str::limit($r['photo'], 40),
                    $r['action'],
                ], $rows, array_keys($rows)),
            );

            // The table truncates; this is the part you can actually verify.
            $proposed = array_values(array_filter($rows, fn ($r) => str_starts_with($r['action'], 'REPLACE')));

            if ($proposed) {
                $this->newLine();
                $this->line('PROPOSED ' . strtoupper($kind) . ' IMAGES — open each URL to verify');
                $this->newLine();

                foreach ($proposed as $i => $r) {
                    $this->line(sprintf('%2d. %s', $i + 1, $r['name']));
                    $this->line('    search term : ' . $r['term']);
                    $this->line('    photo       : ' . $r['photo']);
                    $this->line('    pexels url  : ' . ($r['page'] ?: '(none)'));
                    $this->line('    credit      : ' . $r['credit']);
                    $this->line('    why relevant: ' . $r['why']);
                    $this->line('    action      : IMPORT');
                    $this->newLine();
                }
            }

            $kept = array_values(array_filter($rows, fn ($r) => str_contains($r['action'], 'KEEP')));

            if ($kept) {
                $this->line('KEEPING THE PLACEHOLDER — ' . count($kept));

                foreach ($kept as $r) {
                    $this->line(sprintf('    %-26s %s', Str::limit($r['name'], 25), $r['why']));
                }

                $this->newLine();
            }
        }
    }

    /**
     * The shopping list for a bulk folder import.
     *
     * A slug carries the record's id ("oil-filter-5", not "oil-filter"), so
     * guessing filenames by hand gets them wrong. This prints exactly what to
     * name each file, and — when --folder is given — whether it is already
     * there.
     */
    private function manifest(): void
    {
        $folder = rtrim((string) $this->option('folder'), '/');
        $rows = [];
        $present = 0;

        foreach (Product::with('media')->orderBy('id')->get() as $product) {
            $main = $product->media->firstWhere('is_main', 1) ?? $product->media->first();
            $hasReal = $main && ! $this->isGeneratedPlaceholder('product', $main->path);
            $found = $folder ? $this->fromFolderPath($product) : null;

            if ($found) {
                $present++;
            }

            $rows[] = [
                $product->id,
                Str::limit($product->name, 32),
                $product->slug,
                $product->slug . '.jpg',
                $product->sku ?: '—',
                $hasReal ? 'has real photo' : 'placeholder',
                $found ? '✓ ' . basename($found) : ($folder ? '— missing —' : ''),
            ];
        }

        $this->newLine();
        $this->line('Drop one image per product into your import folder, named as below.');
        $this->line('Accepted extensions: jpg, jpeg, png, webp, gif. The extension does not have to be .jpg.');
        $this->newLine();

        $this->table(
            ['ID', 'Product', 'Slug', 'Expected filename', 'SKU', 'Current image', $folder ? 'In folder' : ''],
            $rows,
        );

        if ($folder) {
            $this->info("{$present} of " . count($rows) . " products have a file in {$folder}.");
        } else {
            $this->line('Pass --folder=<path> as well to see which files are already in place.');
        }

        $this->newLine();
        $this->line('Then import them with:');
        $this->line('  php artisan vipuri:catalog-images --source=folder --folder=<path> --only=products');
    }

    private function provider(string $name): ?StockPhotoProvider
    {
        return match ($name) {
            'pexels' => new PexelsProvider(),
            'unsplash' => new UnsplashProvider(),
            default => null,
        };
    }

    // ------------------------------------------------------------- products

    private function importProducts(FileManager $files, ?StockPhotoProvider $provider): void
    {
        $limit = (int) $this->option('limit');
        $query = Product::with('media')->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get();
        $this->line("Products: {$products->count()}");

        foreach ($products as $product) {
            if ($this->allowed && ! in_array(Str::lower($product->name), $this->allowed, true)) {
                continue;
            }

            $main = $product->media->firstWhere('is_main', 1) ?? $product->media->first();

            if ($main && ! $this->option('force') && ! $this->isGeneratedPlaceholder('product', $main->path)) {
                $this->line("  · {$product->name} — already has a real photo, left alone");
                $this->record('product', $product->name, '—', null, 'SKIP — real photo already');
                $this->skipped++;
                continue;
            }

            $term = $this->productTerm($product->name);
            $this->lastMatch = null;
            $this->lastRejection = null;
            $filename = $this->fetch($files, 'product', $term, $product->slug, $provider, withThumb: true);

            if (! $filename) {
                $this->missingInFolder ? $this->skipped++ : $this->failed++;
                $this->record('product', $product->name, $term['q'], null,
                    $this->missingInFolder ? 'SKIP — no file in folder' : 'KEEP placeholder');
                $this->missingInFolder = false;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->record('product', $product->name, $term['q'], $this->lastMatch, 'REPLACE placeholder');
                $this->imported++;
                continue;
            }

            // Replace the placeholder in place so ordering and ids survive.
            if ($main) {
                $files->removeImage('product', $main->path);
                $main->update(['path' => $filename, 'is_main' => 1]);
            } else {
                ProductMedia::create([
                    'product_id' => $product->id,
                    'path' => $filename,
                    'is_main' => 1,
                ]);
            }

            $this->info("  ✓ {$product->name}  ←  \"{$term['q']}\"");
            $this->imported++;
        }
    }

    // ----------------------------------------------------------- categories

    private function importCategories(FileManager $files, ?StockPhotoProvider $provider): void
    {
        $categories = Category::orderBy('id')->get();
        $this->newLine();
        $this->line("Categories: {$categories->count()}");

        foreach ($categories as $category) {
            if ($this->allowed && ! in_array(Str::lower($category->name), $this->allowed, true)) {
                continue;
            }

            $hasReal = $category->image
                && ! $this->isGeneratedPlaceholder('category', $category->image);

            if ($hasReal && ! $this->option('force')) {
                $this->line("  · {$category->name} — already has a real photo, left alone");
                $this->record('category', $category->name, '—', null, 'SKIP — real photo already');
                $this->skipped++;
                continue;
            }

            $term = $this->categoryTerm($category->name);
            $this->lastMatch = null;
            $this->lastRejection = null;
            $filename = $this->fetch($files, 'category', $term, $category->slug, $provider, withThumb: true);

            if (! $filename) {
                $this->missingInFolder ? $this->skipped++ : $this->failed++;
                $this->record('category', $category->name, $term['q'], null,
                    $this->missingInFolder ? 'SKIP — no file in folder' : 'KEEP placeholder');
                $this->missingInFolder = false;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->record('category', $category->name, $term['q'], $this->lastMatch, 'REPLACE placeholder');
                $this->imported++;
                continue;
            }

            $old = $category->image;
            $oldIcon = $category->icon;

            // The rail shows `icon` and the category page shows `image`; a
            // category with only one of them looks half-finished.
            $category->update(['image' => $filename, 'icon' => $filename]);

            if ($old && $old !== $filename) {
                $files->removeImage('category', $old);
            }
            if ($oldIcon && $oldIcon !== $old && $oldIcon !== $filename) {
                $files->removeImage('category', $oldIcon);
            }

            $this->info("  ✓ {$category->name}  ←  \"{$term['q']}\"");
            $this->imported++;
        }
    }

    // ---------------------------------------------------------------- fetch

    /**
     * Obtain one image and store it through the normal upload path.
     *
     * @return string|null The stored filename, or null on any failure.
     */
    private function fetch(
        FileManager $files,
        string $pathKey,
        array $term,
        string $slug,
        ?StockPhotoProvider $provider,
        bool $withThumb = false,
    ): ?string {
        $temp = null;

        try {
            if ($provider === null) {
                $temp = $this->fromFolder($slug);

                if (! $temp) {
                    $this->line("  · {$slug} — no file in the import folder");
                    $this->missingInFolder = true;

                    return null;
                }
            } else {
                $found = $this->pick($provider, $term);

                if (! $found) {
                    $this->warn("  ! {$slug} — {$this->lastRejection} (\"{$term['q']}\")");

                    return null;
                }

                $this->lastMatch = $found;

                // A dry run answers "what would you pick?", so it stops at the
                // search. Downloading megabytes only to discard them would
                // also burn the provider's rate limit for no reason.
                if ($this->option('dry-run')) {
                    return 'dry-run';
                }

                $temp = $this->download($found['url']);

                if (! $temp) {
                    $this->warn("  ! {$slug} — could not download the image");

                    return null;
                }
            }

            if ($this->option('dry-run')) {
                return 'dry-run';
            }

            // The same call the admin controllers make: MIME sniffed from the
            // bytes, re-encoded, resized, uuid filename.
            return $files->uploadImage(
                new UploadedFile($temp, basename($temp), null, null, true),
                $pathKey,
                withThumb: $withThumb,
            );
        } catch (Throwable $e) {
            $this->warn("  ! {$slug} — {$e->getMessage()}");

            return null;
        } finally {
            if ($temp && is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /** Download to a temp file, refusing anything that is not a sane image. */
    private function download(string $url): ?string
    {
        $response = Http::timeout(30)->retry(2, 500, throw: false)->get($url);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        // 12 MB is far above any legitimate stock photo at these dimensions.
        if (strlen($body) === 0 || strlen($body) > 12 * 1024 * 1024) {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'vipuri_img_');
        file_put_contents($temp, $body);

        // Trust the bytes, not the URL or the Content-Type header.
        if (@getimagesize($temp) === false) {
            @unlink($temp);

            return null;
        }

        return $temp;
    }

    /** Look for this product's image in the folder given by --folder. */
    private function fromFolder(string $slug): ?string
    {
        $product = Product::where('slug', $slug)->first();
        $path = $product ? $this->fromFolderPath($product) : null;

        if (! $path) {
            return null;
        }

        // Copied so the caller's unlink cannot delete the admin's own file.
        $temp = tempnam(sys_get_temp_dir(), 'vipuri_img_');
        copy($path, $temp);

        return $temp;
    }

    /**
     * The file in the import folder belonging to a product, if any.
     *
     * Tolerant on purpose: an admin exporting from a supplier catalogue should
     * not have to rename files to match a slug's trailing id exactly, and
     * should not be caught out by `.JPG` on a case-sensitive filesystem.
     */
    private function fromFolderPath(Product $product): ?string
    {
        $folder = rtrim((string) $this->option('folder'), '/');

        if (! $folder || ! is_dir($folder)) {
            return null;
        }

        $wanted = array_filter([
            Str::lower($product->slug),
            // "oil-filter-5" also answers to "oil-filter".
            Str::lower(preg_replace('/-\d+$/', '', $product->slug)),
            $product->sku ? Str::lower($product->sku) : null,
            Str::lower(Str::slug($product->name)),
        ]);

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        // One pass over the directory, so matching is case-insensitive and the
        // stored extension does not have to be guessed.
        foreach ((array) glob("$folder/*") as $file) {
            if (! is_file($file)) {
                continue;
            }

            $extension = Str::lower(pathinfo($file, PATHINFO_EXTENSION));

            if (! in_array($extension, $allowed, true)) {
                continue;
            }

            $stem = Str::lower(pathinfo($file, PATHINFO_FILENAME));

            if (in_array($stem, $wanted, true) && @getimagesize($file) !== false) {
                return $file;
            }
        }

        return null;
    }

    // ------------------------------------------------------------ detection

    /**
     * Whether a stored file is seeder-generated placeholder art.
     *
     * The generator draws flat shapes on a flat background, so a handful of
     * distinct colours. A photograph has thousands. This is what stops the
     * import from overwriting photography an admin uploaded by hand.
     */
    private function isGeneratedPlaceholder(string $pathKey, ?string $filename): bool
    {
        if (! $filename) {
            return true;
        }

        $path = getFilePath($pathKey) . '/' . $filename;

        if (! Storage::disk('public')->exists($path)) {
            return true;
        }

        $bytes = Storage::disk('public')->get($path);
        $image = @imagecreatefromstring($bytes);

        if (! $image) {
            // Unreadable — treat as replaceable; it renders as nothing anyway.
            return true;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $seen = [];

        // Every 4th pixel is ample to pass the threshold on a real photo.
        for ($x = 0; $x < $width; $x += 4) {
            for ($y = 0; $y < $height; $y += 4) {
                $seen[imagecolorat($image, $x, $y)] = true;

                if (count($seen) > self::PLACEHOLDER_MAX_COLOURS) {
                    imagedestroy($image);

                    return false;
                }
            }
        }

        imagedestroy($image);

        return true;
    }

    // ----------------------------------------------------------------- terms

    /** @return array{q: string, expect: list<string>} */
    private function productTerm(string $name): array
    {
        $haystack = Str::lower($name);

        foreach ((array) config('catalog_images.products', []) as $keyword => $entry) {
            if (str_contains($haystack, (string) $keyword)) {
                return ['q' => $entry['q'], 'expect' => $entry['expect'] ?? []];
            }
        }

        // The fallback carries no expectations, so anything it returns passes.
        return ['q' => (string) config('catalog_images.product_fallback', 'car spare parts'), 'expect' => []];
    }

    /** @return array{q: string, expect: list<string>} */
    private function categoryTerm(string $name): array
    {
        $entry = ((array) config('catalog_images.categories', []))[Str::lower(trim($name))] ?? null;

        return $entry
            ? ['q' => $entry['q'], 'expect' => $entry['expect'] ?? []]
            : ['q' => (string) config('catalog_images.category_fallback', 'car spare parts shop'), 'expect' => []];
    }

    /**
     * Choose a candidate that is actually a picture of the thing.
     *
     * A stock library will always return *something* — searching "grease
     * cartridge lubricant" returned a photograph of a pizza. So a result is
     * only accepted if the provider's own description mentions one of the
     * words we expect, and a record keeps its placeholder rather than being
     * given a picture of the wrong object.
     */
    private function pick(StockPhotoProvider $provider, array $term): ?array
    {
        $query = $term['q'];

        if (! array_key_exists($query, $this->candidateCache)) {
            // Several at once: one API call serves every record sharing this
            // term, and leaves room to skip duplicates and rejects.
            $this->candidateCache[$query] = $provider->search($query, 15);
        }

        $candidates = $this->candidateCache[$query];

        if (! $candidates) {
            $this->lastRejection = 'provider returned nothing';

            return null;
        }

        $sawRelevant = false;
        $firstReason = null;

        foreach ($candidates as $candidate) {
            $verdict = $this->judge($candidate['alt'], $term['expect']);

            if (! $verdict['ok']) {
                $firstReason ??= $verdict['why'];
                continue;
            }

            $sawRelevant = true;

            if (in_array($candidate['url'], $this->usedUrls, true)) {
                continue;
            }

            $this->usedUrls[] = $candidate['url'];

            return $candidate + ['why' => $verdict['why']];
        }

        $this->lastRejection = $sawRelevant
            ? 'only duplicates left for this term'
            : ($firstReason ?? 'nothing relevant returned');

        return null;
    }

    /**
     * Judge a candidate, and say why.
     *
     * Three conditions, all general — none of them names a category:
     *   1. the description mentions one of the words we expect,
     *   2. it mentions something automotive or workshop-related, so a coffee
     *      filter cannot stand in for an oil filter,
     *   3. it mentions nothing that gives away a different domain.
     *
     * @return array{ok: bool, why: string}
     */
    private function judge(string $alt, array $expect): array
    {
        $haystack = Str::lower($alt);

        $matched = $this->firstWord($haystack, $expect);

        if ($expect && ! $matched) {
            return ['ok' => false, 'why' => 'no mention of ' . implode('/', $expect)];
        }

        $blocked = $this->firstWord($haystack, (array) config('catalog_images.guard.exclude', []));

        if ($blocked) {
            return ['ok' => false, 'why' => "mentions \"{$blocked}\" — different subject"];
        }

        $context = $this->firstWord($haystack, (array) config('catalog_images.guard.context', []));

        if (! $context) {
            return ['ok' => false, 'why' => 'nothing automotive in the description'];
        }

        return [
            'ok' => true,
            'why' => $matched
                ? "shows \"{$matched}\" in an automotive setting (\"{$context}\")"
                : "automotive subject (\"{$context}\")",
        ];
    }

    /** The first of [$words] present in $haystack as a whole word, or null. */
    private function firstWord(string $haystack, array $words): ?string
    {
        foreach ($words as $word) {
            $word = Str::lower($word);

            // Whole words only, allowing a plural. A substring test matched
            // "engine" inside "audio engineer" and "spark" inside "sparkler".
            if (preg_match('/\b' . preg_quote($word, '/') . '(?:e?s)?\b/u', $haystack)) {
                return $word;
            }
        }

        return null;
    }
}
