<?php

namespace Database\Seeders;

use App\Constants\Roles;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Development staff accounts.
 *
 * The password comes from SEED_PASSWORD in .env — nothing is hard-coded, so a
 * production deployment cannot inherit a known credential.
 */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_PASSWORD');

        if (! $password) {
            $this->command?->warn('SEED_PASSWORD is not set — skipping staff accounts. Set it in .env and re-run.');

            return;
        }

        $company = Company::current();

        $superAdmin = Admin::updateOrCreate(
            ['email' => 'admin@vipuri.co.tz'],
            [
                'company_id' => $company->id,
                'branch_id' => null,
                'name' => 'VIPURI Super Admin',
                'username' => 'superadmin',
                'dial_code' => '+255',
                'mobile' => '754000001',
                'password' => $password,
                'status' => 1,
                'email_verified_at' => now(),
            ],
        );

        $superAdmin->syncRoles([Roles::SUPER_ADMIN]);

        $staff = [
            ['DSM-01', 'Amina Hassan', 'amina.hassan@vipuri.co.tz', 'amina.hassan', Roles::BRANCH_MANAGER, '754000011'],
            ['DSM-01', 'Juma Mtenga', 'juma.mtenga@vipuri.co.tz', 'juma.mtenga', Roles::BRANCH_WORKER, '754000012'],
            ['DSM-01', 'Grace Mollel', 'grace.mollel@vipuri.co.tz', 'grace.mollel', Roles::BRANCH_WORKER, '754000013'],
            ['DSM-02', 'Peter Nyoni', 'peter.nyoni@vipuri.co.tz', 'peter.nyoni', Roles::BRANCH_MANAGER, '754000021'],
            ['DSM-02', 'Fatma Salum', 'fatma.salum@vipuri.co.tz', 'fatma.salum', Roles::BRANCH_WORKER, '754000022'],
            ['ARU-01', 'Neema Laizer', 'neema.laizer@vipuri.co.tz', 'neema.laizer', Roles::BRANCH_MANAGER, '754000031'],
            ['ARU-01', 'Baraka Mushi', 'baraka.mushi@vipuri.co.tz', 'baraka.mushi', Roles::BRANCH_WORKER, '754000032'],
            ['MWZ-01', 'Joseph Charles', 'joseph.charles@vipuri.co.tz', 'joseph.charles', Roles::BRANCH_MANAGER, '754000041'],
            ['MWZ-01', 'Rehema Kajuna', 'rehema.kajuna@vipuri.co.tz', 'rehema.kajuna', Roles::BRANCH_WORKER, '754000042'],
            ['DOM-01', 'Salma Ally', 'salma.ally@vipuri.co.tz', 'salma.ally', Roles::BRANCH_MANAGER, '754000051'],
            ['DOM-01', 'Emmanuel Kimaro', 'emmanuel.kimaro@vipuri.co.tz', 'emmanuel.kimaro', Roles::BRANCH_WORKER, '754000052'],
        ];

        foreach ($staff as [$branchCode, $name, $email, $username, $role, $mobile]) {
            $branch = Branch::where('code', $branchCode)->first();

            if (! $branch) {
                continue;
            }

            $member = Admin::updateOrCreate(
                ['email' => $email],
                [
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'name' => $name,
                    'username' => $username,
                    'dial_code' => '+255',
                    'mobile' => $mobile,
                    'password' => $password,
                    'status' => 1,
                    'email_verified_at' => now(),
                ],
            );

            $member->syncRoles([$role]);
        }
    }
}
