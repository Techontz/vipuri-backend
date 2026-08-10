<?php

/**
 * Search terms used by `vipuri:catalog-images` to find photography for the
 * catalogue.
 *
 * A product's own name is a poor search query — "Brake Calliper — Front Right,
 * Toyota Hilux" returns nothing on a stock photo service. So each keyword that
 * may appear in a product name maps to a query that actually returns a picture
 * of that part. The first keyword found in the name wins, which is why the list
 * is ordered most-specific first: "brake fluid" must be tested before "brake".
 *
 * Editing this file is the supported way to improve a bad match — rerun the
 * command with --force for the affected products afterwards.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Product keyword → stock photo query
    |--------------------------------------------------------------------------
    */
    'products' => [
        'brake fluid' => ['q' => 'brake fluid', 'expect' => ['brake']],
        'engine oil' => ['q' => 'motor oil bottle', 'expect' => ['oil']],
        'gear oil' => ['q' => 'gear oil', 'expect' => ['oil']],
        'coolant' => ['q' => 'engine coolant', 'expect' => ['coolant', 'radiator']],
        'grease' => ['q' => 'grease lubricant', 'expect' => ['grease']],
        'lubricant' => ['q' => 'motor oil bottle', 'expect' => ['oil', 'lubricant']],
        'brake pad' => ['q' => 'brake pads', 'expect' => ['brake']],
        'brake disc' => ['q' => 'brake disc', 'expect' => ['brake', 'disc', 'rotor']],
        'brake rotor' => ['q' => 'brake rotor', 'expect' => ['brake', 'rotor', 'disc']],
        'calliper' => ['q' => 'brake caliper', 'expect' => ['brake', 'caliper', 'calliper']],
        'caliper' => ['q' => 'brake caliper', 'expect' => ['brake', 'caliper', 'calliper']],
        'oil filter' => ['q' => 'car oil filter', 'expect' => ['oil filter', 'filter']],
        'air filter' => ['q' => 'air filter', 'expect' => ['filter']],
        'fuel filter' => ['q' => 'fuel filter', 'expect' => ['filter']],
        'cabin filter' => ['q' => 'cabin air filter', 'expect' => ['filter']],
        'filter' => ['q' => 'car filter', 'expect' => ['filter']],
        'spark plug' => ['q' => 'spark plug', 'expect' => ['spark plug', 'spark plugs']],
        'timing belt' => ['q' => 'timing belt', 'expect' => ['belt', 'engine']],
        'piston' => ['q' => 'piston rings', 'expect' => ['piston', 'engine']],
        'gasket' => ['q' => 'engine gasket', 'expect' => ['gasket', 'seal']],
        'radiator' => ['q' => 'car radiator', 'expect' => ['radiator', 'coolant']],
        'water pump' => ['q' => 'water pump engine', 'expect' => ['pump', 'engine']],
        'service kit' => ['q' => 'mechanic tool kit', 'expect' => ['tool', 'kit', 'repair', 'mechanic']],
        'battery' => ['q' => 'car battery isolated', 'expect' => ['battery']],
        'alternator' => ['q' => 'car alternator', 'expect' => ['alternator', 'engine']],
        'starter' => ['q' => 'starter motor', 'expect' => ['starter', 'motor', 'engine']],
        'sensor' => ['q' => 'car engine sensor', 'expect' => ['sensor', 'engine']],
        'headlight' => ['q' => 'car headlight', 'expect' => ['headlight', 'head light', 'lamp']],
        'tail light' => ['q' => 'car tail light', 'expect' => ['tail', 'light', 'lamp']],
        'head unit' => ['q' => 'car stereo', 'expect' => ['stereo', 'radio', 'dashboard', 'audio']],
        'dash camera' => ['q' => 'dash cam', 'expect' => ['dash', 'camera', 'cam']],
        'dash cam' => ['q' => 'dash cam', 'expect' => ['dash', 'camera', 'cam']],
        'shock absorber' => ['q' => 'shock absorber', 'expect' => ['shock', 'absorber', 'suspension']],
        'strut' => ['q' => 'suspension strut', 'expect' => ['strut', 'suspension']],
        'ball joint' => ['q' => 'ball joint', 'expect' => ['joint', 'suspension', 'steering']],
        'control arm' => ['q' => 'control arm suspension', 'expect' => ['arm', 'suspension']],
        'steering rack' => ['q' => 'steering rack', 'expect' => ['steering rack', 'rack']],
        'suspension' => ['q' => 'car suspension', 'expect' => ['suspension']],
        'side mirror' => ['q' => 'car side mirror', 'expect' => ['mirror']],
        'mirror' => ['q' => 'car side mirror', 'expect' => ['mirror']],
        'bumper' => ['q' => 'car bumper', 'expect' => ['bumper']],
        'wiper' => ['q' => 'windshield wiper', 'expect' => ['wiper', 'wipers']],
        'floor mat' => ['q' => 'car floor mat', 'expect' => ['floor mat', 'floor mats', 'car mat']],
        'seat cover' => ['q' => 'car seat', 'expect' => ['seat', 'interior']],
        'tyre' => ['q' => 'car tyre', 'expect' => ['tyre', 'tire', 'wheel']],
        'tire' => ['q' => 'car tyre', 'expect' => ['tyre', 'tire', 'wheel']],
        'rim' => ['q' => 'alloy wheel', 'expect' => ['wheel', 'rim', 'alloy']],
        'wheel bearing' => ['q' => 'wheel bearing', 'expect' => ['bearing', 'hub']],
        'diagnostic' => ['q' => 'obd2 scanner', 'expect' => ['obd', 'scanner', 'diagnostic', 'reader', 'code']],
        'scanner' => ['q' => 'obd2 scanner', 'expect' => ['obd', 'scanner', 'diagnostic', 'reader', 'code']],
        'trolley jack' => ['q' => 'car jack lifting', 'expect' => ['jack stand', 'car jack', 'jack lift', 'lifting']],
        'jack' => ['q' => 'car jack lifting', 'expect' => ['jack stand', 'car jack', 'jack lift', 'lifting']],
        'socket wrench' => ['q' => 'socket wrench set', 'expect' => ['wrench', 'socket', 'tool', 'spanner']],
        'wrench' => ['q' => 'wrench set', 'expect' => ['wrench', 'tool', 'spanner']],
        'tool' => ['q' => 'mechanic tools', 'expect' => ['tool', 'wrench', 'workshop', 'mechanic']],
    ],

    /**
     * Used when no keyword above matches the product name. It has no `expect`
     * list, so anything it returns is accepted — keep it generic enough that
     * any car photograph is a defensible choice.
     */
    'product_fallback' => 'car spare parts',

    /*
    |--------------------------------------------------------------------------
    | Category name → stock photo query
    |--------------------------------------------------------------------------
    |
    | Matched on the category's own name, case-insensitively.
    */
    'categories' => [
        'engine parts' => ['q' => 'car engine', 'expect' => ['engine']],
        'filters' => ['q' => 'car air filter', 'expect' => ['filter']],
        'belts & hoses' => ['q' => 'engine belt', 'expect' => ['belt', 'hose']],
        'gaskets & seals' => ['q' => 'engine gasket', 'expect' => ['gasket', 'seal']],
        'pistons & rings' => ['q' => 'piston rings', 'expect' => ['piston', 'engine']],
        'cooling system' => ['q' => 'car radiator', 'expect' => ['radiator', 'coolant']],
        'brake system' => ['q' => 'car brakes', 'expect' => ['brake']],
        'brake pads' => ['q' => 'brake pads', 'expect' => ['brake pad', 'brake pads', 'brake']],
        'brake discs' => ['q' => 'brake disc', 'expect' => ['brake', 'disc', 'rotor']],
        'brake fluid' => ['q' => 'brake fluid', 'expect' => ['brake']],
        'brake callipers' => ['q' => 'brake caliper', 'expect' => ['brake', 'caliper', 'calliper']],
        'suspension & steering' => ['q' => 'car suspension', 'expect' => ['suspension', 'steering']],
        'shock absorbers' => ['q' => 'shock absorber', 'expect' => ['shock', 'absorber', 'suspension']],
        'ball joints' => ['q' => 'ball joint', 'expect' => ['joint', 'suspension', 'steering']],
        'control arms' => ['q' => 'control arm suspension', 'expect' => ['arm', 'suspension']],
        'steering racks' => ['q' => 'steering rack', 'expect' => ['steering rack', 'rack']],
        'electrical' => ['q' => 'car wiring harness', 'expect' => ['wiring', 'harness', 'alternator', 'battery']],
        'batteries' => ['q' => 'car battery isolated', 'expect' => ['battery']],
        'alternators' => ['q' => 'car alternator', 'expect' => ['alternator', 'engine']],
        'starters' => ['q' => 'starter motor', 'expect' => ['starter', 'motor', 'engine']],
        'spark plugs' => ['q' => 'spark plug', 'expect' => ['spark plug', 'spark plugs']],
        'sensors' => ['q' => 'car engine sensor', 'expect' => ['sensor', 'engine']],
        'tyres & wheels' => ['q' => 'car wheels tyres', 'expect' => ['wheel', 'tyre', 'tire']],
        'tyres' => ['q' => 'car tyre', 'expect' => ['tyre', 'tire', 'wheel']],
        'alloy rims' => ['q' => 'alloy wheel', 'expect' => ['wheel', 'rim', 'alloy']],
        'wheel bearings' => ['q' => 'wheel bearing', 'expect' => ['bearing', 'hub']],
        'body & exterior' => ['q' => 'car body panel', 'expect' => ['car', 'body', 'panel', 'exterior']],
        // "car headlight" returns a night-fog shot first: correct subject, but
        // 92% of its pixels are near-black, which is unreadable at tile size.
        // A close-up fills the frame with the part itself and stays bright.
        'headlights' => ['q' => 'headlight close up', 'expect' => ['headlight', 'head light', 'lamp']],
        'mirrors' => ['q' => 'car side mirror', 'expect' => ['mirror']],
        'bumpers' => ['q' => 'car bumper', 'expect' => ['bumper']],
        'wipers' => ['q' => 'windshield wiper', 'expect' => ['wiper', 'wipers']],
        'interior & accessories' => ['q' => 'car interior', 'expect' => ['interior', 'dashboard', 'seat']],
        'seat covers' => ['q' => 'car seat', 'expect' => ['seat', 'interior']],
        'floor mats' => ['q' => 'car floor mat', 'expect' => ['floor mat', 'floor mats', 'car mat']],
        'car audio' => ['q' => 'car stereo', 'expect' => ['stereo', 'radio', 'dashboard', 'audio']],
        'dash cameras' => ['q' => 'dash cam car', 'expect' => ['dash cam', 'dashcam', 'dash camera']],
        'lubricants & fluids' => ['q' => 'motor oil', 'expect' => ['motor oil', 'engine oil', 'lubricant']],
        'engine oil' => ['q' => 'motor oil bottle', 'expect' => ['oil']],
        'gear oil' => ['q' => 'gear oil', 'expect' => ['oil']],
        'coolant' => ['q' => 'engine coolant', 'expect' => ['coolant', 'radiator']],
        'grease' => ['q' => 'grease lubricant', 'expect' => ['grease', 'lubricant']],
        'tools & garage' => ['q' => 'mechanic workshop tools', 'expect' => ['tool', 'workshop', 'garage', 'mechanic']],
        'hand tools' => ['q' => 'wrench set', 'expect' => ['wrench', 'tool', 'spanner']],
        'jacks & stands' => ['q' => 'car jack lifting', 'expect' => ['jack stand', 'car jack', 'jack lift', 'lifting']],
        'diagnostic tools' => ['q' => 'obd2 scanner', 'expect' => ['obd', 'scanner', 'diagnostic', 'reader', 'code']],
    ],

    'category_fallback' => 'car spare parts shop',

    /*
    |--------------------------------------------------------------------------
    | Relevance guard
    |--------------------------------------------------------------------------
    |
    | A stock library always returns *something*. Searching "grease lubricant"
    | returned a photograph of a pizza; "car jack" returned a Mini Cooper with a
    | Union Jack on the roof. So a result is accepted only when all three hold:
    |
    |   1. its description mentions one of the entry's `expect` words,
    |   2. it mentions something from `context` — it is a picture of a vehicle
    |      or a workshop, not of a kitchen,
    |   3. it mentions nothing from `exclude`.
    |
    | These are general vocabulary, not a list of banned categories: a genuinely
    | relevant photo of any category still passes on its own merits.
    |
    */
    'guard' => [

        // Proof the photograph is automotive or workshop subject matter.
        'context' => [
            'car', 'cars', 'vehicle', 'auto', 'automobile', 'automotive',
            'engine', 'motor', 'truck', 'suv', 'sedan', 'hatchback', 'van',
            'bonnet', 'hood', 'chassis', 'dashboard', 'windshield', 'windscreen',
            'garage', 'mechanic', 'workshop', 'repair', 'tool', 'tools',
            'toolbox', 'spanner', 'wrench', 'tyre', 'tire', 'wheel',
        ],

        // Giveaways that the search landed on a homonym in another domain.
        'exclude' => [
            // Wrong vehicle — VIPURI sells car parts.
            'motorcycle', 'motorbike', 'bicycle', 'bike', 'scooter',
            'airplane', 'aeroplane', 'aircraft', 'plane', 'jet', 'turbine',
            'boat', 'ship', 'tractor', 'train', 'locomotive',
            // Wrong domain entirely.
            'coffee', 'espresso', 'kitchen', 'food', 'pizza', 'cooking',
            'gym', 'yoga', 'fitness', 'pilates',
            'cbd', 'essential oil', 'perfume', 'cosmetic', 'skincare',
            'supplement', 'medicine', 'towel', 'bathroom', 'bedroom',
            'sparkler', 'firework', 'candle',
            // A workshop word alone is not proof of a *car* workshop.
            'chainsaw', 'lawnmower', 'sewing', 'carpentry', 'woodwork',
            'tripod', 'flag', 'decal',
        ],
    ],
];
