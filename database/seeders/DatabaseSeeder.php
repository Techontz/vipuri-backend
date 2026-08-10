<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            RolePermissionSeeder::class,
            StaffSeeder::class,
            SettingSeeder::class,
            GatewaySeeder::class,
            CatalogSeeder::class,
            CommerceSeeder::class,
        ]);

        $this->command?->newLine();
        $this->command?->info('VIPURI seed complete.');
        $this->command?->line('  Super admin  : admin@vipuri.co.tz');
        $this->command?->line('  Branch mgr   : amina.hassan@vipuri.co.tz (Kariakoo)');
        $this->command?->line('  Branch worker: juma.mtenga@vipuri.co.tz (Kariakoo)');
        $this->command?->line('  Customer     : asha.mwinyi@example.co.tz');
        $this->command?->line('  Password     : the value of SEED_PASSWORD in your .env');
    }
}
