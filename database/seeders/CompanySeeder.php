<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * The single VIPURI company plus its physical branches.
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['slug' => 'vipuri'],
            [
                'name' => 'VIPURI',
                'legal_name' => 'VIPURI Auto Parts Limited',
                'email' => 'info@vipuri.co.tz',
                'phone' => '+255 22 286 1000',
                'tin' => '123-456-789',
                'vrn' => '40-123456-A',
                'address' => 'Plot 45, Nyerere Road, Industrial Area',
                'city' => 'Dar es Salaam',
                'region' => 'Dar es Salaam',
                'country_name' => 'Tanzania',
                'country_code' => 'TZ',
                'currency_text' => 'TZS',
                'currency_symbol' => 'TSh',
                'status' => 1,
            ],
        );

        $branches = [
            [
                'name' => 'VIPURI Kariakoo',
                'code' => 'DSM-01',
                'slug' => 'vipuri-kariakoo',
                'email' => 'kariakoo@vipuri.co.tz',
                'phone' => '255 22 218 4400',
                'address' => 'Msimbazi Street, Kariakoo',
                'city' => 'Dar es Salaam',
                'region' => 'Dar es Salaam',
                'latitude' => -6.8189,
                'longitude' => 39.2762,
                'is_default' => true,
            ],
            [
                'name' => 'VIPURI Nyerere Road',
                'code' => 'DSM-02',
                'slug' => 'vipuri-nyerere-road',
                'email' => 'nyerere@vipuri.co.tz',
                'phone' => '255 22 286 1000',
                'address' => 'Plot 45, Nyerere Road, Industrial Area',
                'city' => 'Dar es Salaam',
                'region' => 'Dar es Salaam',
                'latitude' => -6.8447,
                'longitude' => 39.2503,
            ],
            [
                'name' => 'VIPURI Arusha',
                'code' => 'ARU-01',
                'slug' => 'vipuri-arusha',
                'email' => 'arusha@vipuri.co.tz',
                'phone' => '255 27 254 4400',
                'address' => 'Sokoine Road, Kaloleni',
                'city' => 'Arusha',
                'region' => 'Arusha',
                'latitude' => -3.3869,
                'longitude' => 36.6829,
            ],
            [
                'name' => 'VIPURI Mwanza',
                'code' => 'MWZ-01',
                'slug' => 'vipuri-mwanza',
                'email' => 'mwanza@vipuri.co.tz',
                'phone' => '255 28 250 1100',
                'address' => 'Kenyatta Road, Nyamagana',
                'city' => 'Mwanza',
                'region' => 'Mwanza',
                'latitude' => -2.5164,
                'longitude' => 32.9175,
            ],
            [
                'name' => 'VIPURI Dodoma',
                'code' => 'DOM-01',
                'slug' => 'vipuri-dodoma',
                'email' => 'dodoma@vipuri.co.tz',
                'phone' => '255 26 232 2200',
                'address' => 'Ninth Street, Area C',
                'city' => 'Dodoma',
                'region' => 'Dodoma',
                'latitude' => -6.1630,
                'longitude' => 35.7516,
            ],
        ];

        $hours = [
            'monday' => '08:00 - 18:00',
            'tuesday' => '08:00 - 18:00',
            'wednesday' => '08:00 - 18:00',
            'thursday' => '08:00 - 18:00',
            'friday' => '08:00 - 18:00',
            'saturday' => '09:00 - 16:00',
            'sunday' => 'Closed',
        ];

        foreach ($branches as $branch) {
            Branch::updateOrCreate(
                ['code' => $branch['code']],
                $branch + [
                    'company_id' => $company->id,
                    'dial_code' => '+255',
                    'is_pickup_point' => true,
                    'status' => 1,
                    'opening_hours' => $hours,
                ],
            );
        }
    }
}
