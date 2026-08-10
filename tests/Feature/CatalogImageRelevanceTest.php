<?php

namespace Tests\Feature;

use App\Console\Commands\ImportCatalogImages;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The relevance guard, exercised against what Pexels actually returned.
 *
 * A stock library always answers. Searching "grease lubricant" returned a
 * photograph of a pizza; "car jack" returned a Mini Cooper with a Union Jack on
 * the roof; "car radiator" returned a bathroom towel rail. Every description
 * below is real — taken from a live dry run — so these tests pin the guard
 * against the exact failures that were observed rather than invented ones.
 *
 * The rules are general vocabulary, never a list of banned categories: each
 * case is rejected because of what the picture *is*, so a genuinely relevant
 * photograph of the same category still passes.
 */
class CatalogImageRelevanceTest extends TestCase
{
    private function judge(string $alt, array $expect): array
    {
        $method = new ReflectionMethod(ImportCatalogImages::class, 'judge');
        $method->setAccessible(true);

        return $method->invoke(app(ImportCatalogImages::class), $alt, $expect);
    }

    private function expectFor(string $category): array
    {
        return config("catalog_images.categories.{$category}.expect", []);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function homonyms(): array
    {
        // [category key, the description Pexels actually returned, what it is]
        return [
            ['filters', 'A detailed top view of a coffee dripper with a paper filter', 'coffee filter'],
            ['belts & hoses', 'A detailed view of a vintage airplane turbine engine', 'aircraft'],
            ['cooling system', 'Sleek white towel radiator against a dark wall', 'bathroom radiator'],
            ['brake pads', 'Detailed view of a Kawasaki motorcycle with brake discs', 'motorcycle'],
            ['electrical', 'A messy web of power lines and cables on a street', 'street cabling'],
            ['spark plugs', 'A vibrant sparkler illuminates the night sky', 'a sparkler'],
            ['wheel bearings', 'Detailed view of a shiny vintage Excalibur car wheel', 'a whole car'],
            ['wipers', 'Artistic view of night traffic lights through glass', 'traffic lights'],
            ['floor mats', 'An overhead view of a yellow gym mat with weights', 'gym mat'],
            ['dash cameras', 'Camera on tripod capturing a modern bridge', 'a tripod camera'],
            ['lubricants & fluids', 'High-quality CBD oil designed for sports recovery', 'CBD oil'],
            ['jacks & stands', 'Red Mini Cooper with a Union Jack decal on the roof', 'a Union Jack'],
            ['gaskets & seals', 'Close-up shot of a vintage International tractor', 'a tractor'],
            ['steering racks', 'Detailed view of a woman holding a car steering wheel', 'a steering wheel'],
        ];
    }

    #[DataProvider('homonyms')]
    public function test_a_homonym_from_another_domain_is_refused(
        string $category,
        string $alt,
        string $whatItReallyIs,
    ): void {
        $verdict = $this->judge($alt, $this->expectFor($category));

        $this->assertFalse(
            $verdict['ok'],
            "'{$category}' accepted a photograph of {$whatItReallyIs}: \"{$alt}\"",
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function goodMatches(): array
    {
        return [
            ['headlights', 'Mysterious car headlights cutting through fog'],
            ['mirrors', "A detailed view of a vintage car's side mirror"],
            ['bumpers', "A vintage car's bumper shines amidst a winter scene"],
            ['interior & accessories', 'Explore the luxurious leather interior of a modern car'],
            ['tyres & wheels', "A detailed view of a white SUV's wheel on asphalt"],
            ['alloy rims', 'Close-up of a classic blue automobile with alloy wheels'],
            ['tools & garage', 'A woman using a tool kit while repairing a car'],
            ['body & exterior', 'Detailed view of a vintage car showing its body panels'],
            ['seat covers', 'Back view of man sitting inside a car seat'],
            ['car audio', 'Close-up image of a luxury car dashboard and stereo'],
        ];
    }

    #[DataProvider('goodMatches')]
    public function test_a_genuinely_relevant_photograph_still_passes(
        string $category,
        string $alt,
    ): void {
        $verdict = $this->judge($alt, $this->expectFor($category));

        $this->assertTrue(
            $verdict['ok'],
            "'{$category}' rejected a relevant photograph: \"{$alt}\" — {$verdict['why']}",
        );
    }

    public function test_the_guard_is_vocabulary_not_a_banned_category_list(): void
    {
        // The same categories rejected above accept a real photograph of
        // themselves. Nothing about the category is permanently blocked.
        $cases = [
            ['filters', 'A new car engine air filter held by a mechanic in a garage'],
            ['spark plugs', 'Four spark plugs laid out on a workshop bench'],
            ['floor mats', 'Rubber car floor mats fitted in a vehicle footwell'],
            ['wheel bearings', 'A wheel bearing and hub assembly on a workshop bench'],
            ['jacks & stands', 'A car jack lifting a vehicle in a garage'],
            ['steering racks', 'A steering rack removed from a car in a workshop'],
        ];

        foreach ($cases as [$category, $alt]) {
            $verdict = $this->judge($alt, $this->expectFor($category));

            $this->assertTrue(
                $verdict['ok'],
                "'{$category}' is behaving like a blocklist — it refused \"{$alt}\": {$verdict['why']}",
            );
        }
    }

    public function test_the_reason_explains_the_decision(): void
    {
        $accepted = $this->judge('Mysterious car headlights cutting through fog', ['headlight']);
        $this->assertStringContainsString('headlight', $accepted['why']);

        $rejected = $this->judge('A vibrant sparkler illuminates the night', ['spark plug']);
        $this->assertStringContainsString('spark plug', $rejected['why']);
    }
}
