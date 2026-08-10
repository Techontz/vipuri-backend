<?php

/**
 * Verify that category imagery is actually reachable, end to end.
 *
 * Reads the public categories endpoint, resolves each named category by slug —
 * walking into subcategories, since most of them are nested — takes the `image`
 * and `icon` URLs the API really returned, fetches each one, and reports the
 * status and content type.
 *
 * Stored filenames are UUIDs, so they carry no trace of the category name.
 * Grepping the payload for a category's name therefore proves nothing: it
 * matches nothing whether the upload worked or not. The only sound check is to
 * resolve the record, then follow the URL it gave you.
 *
 * Written in PHP because the API host already has it; no jq or python needed.
 *
 * Usage:
 *   php scripts/verify-category-images.php https://api.vipuri.co.tz
 *   php scripts/verify-category-images.php https://api.vipuri.co.tz brake-system,mirrors
 *
 * Exit code 0 when every checked field is a reachable image, 1 otherwise.
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');

$slugs = isset($argv[2])
    ? array_filter(array_map('trim', explode(',', $argv[2])))
    : [
        'brake-system', 'brake-discs', 'tyres-wheels', 'tyres', 'alloy-rims',
        'body-exterior', 'headlights', 'mirrors', 'bumpers',
        'interior-accessories', 'seat-covers', 'car-audio', 'tools-garage',
        'hand-tools',
    ];

/** GET a URL, returning [status, contentType, bytes]. */
function fetch(string $url, bool $body = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_NOBODY => ! $body,
        CURLOPT_USERAGENT => 'vipuri-verify/1.0',
    ]);

    $payload = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $size = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);

    return [$status, $type, $size, $payload];
}

/** Flatten the category tree, keyed by slug. */
function flatten(array $nodes, array &$out = []): array
{
    foreach ($nodes as $node) {
        if (isset($node['slug'])) {
            $out[$node['slug']] = $node;
        }

        if (! empty($node['subcategories'])) {
            flatten($node['subcategories'], $out);
        }
    }

    return $out;
}

echo "Verifying category imagery against {$base}\n";
echo str_repeat('=', 96), "\n";

[$status, , , $payload] = fetch("{$base}/api/v1/categories");

if ($status !== 200) {
    fwrite(STDERR, "FAILED: categories endpoint returned HTTP {$status}\n");
    exit(1);
}

$json = json_decode((string) $payload, true);
$tree = $json['data']['categories'] ?? [];

if (! $tree) {
    fwrite(STDERR, "FAILED: no categories in the response body\n");
    exit(1);
}

$bySlug = flatten($tree);
printf("Found %d categories in the payload\n\n", count($bySlug));

printf("%-22s %-7s %-6s %-12s %9s  %s\n", 'CATEGORY', 'FIELD', 'HTTP', 'CONTENT-TYPE', 'BYTES', 'RESULT');
echo str_repeat('-', 96), "\n";

$failures = [];

foreach ($slugs as $slug) {
    $node = $bySlug[$slug] ?? null;

    if (! $node) {
        printf("%-22s %-7s %-6s %-12s %9s  %s\n", $slug, '—', '—', '—', '—', 'NOT IN API');
        $failures[] = "{$slug}: not present in the categories payload";
        continue;
    }

    foreach (['image', 'icon'] as $field) {
        $url = $node[$field] ?? null;

        if (! $url) {
            printf("%-22s %-7s %-6s %-12s %9s  %s\n", $slug, $field, '—', '—', '—', 'EMPTY — not uploaded');
            $failures[] = "{$slug}.{$field}: no URL returned by the API";
            continue;
        }

        [$code, $type, $bytes] = fetch($url);
        $isImage = str_starts_with($type, 'image/');
        $ok = $code === 200 && $isImage && $bytes > 0;

        if (! $ok) {
            $failures[] = "{$slug}.{$field}: HTTP {$code}, type '{$type}' — {$url}";
        }

        printf(
            "%-22s %-7s %-6s %-12s %9s  %s\n",
            $slug, $field, $code, $type ?: '—', number_format($bytes), $ok ? 'OK' : 'FAIL'
        );
    }
}

echo str_repeat('-', 96), "\n";

if ($failures) {
    printf("\n%d problem(s):\n", count($failures));
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

printf("\nAll %d categories: image and icon both return an image over HTTP 200.\n", count($slugs));
exit(0);
