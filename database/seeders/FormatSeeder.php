<?php

namespace Database\Seeders;

use App\Models\Format;
use Illuminate\Database\Seeder;

class FormatSeeder extends Seeder
{
    /**
     * Seed the application's supported formats.
     */
    public function run(): void
    {
        $formats = [
            [
                'name' => 'Classic Constructed',
                'abbreviation' => 'CC',
                'link' => 'https://fabtcg.com/',
                'display_order' => 1,
            ],
            [
                'name' => 'Living Legend',
                'abbreviation' => 'LL',
                'link' => 'https://fabtcg.com/',
                'display_order' => 2,
            ],
            [
                'name' => 'Silver Age',
                'abbreviation' => 'SAGE',
                'link' => 'https://fabtcg.com/',
                'display_order' => 3,
            ],
            [
                'name' => 'Golden Age',
                'abbreviation' => 'GAGE',
                'link' => 'https://goldenagefab.com/',
                'display_order' => 4,
            ],
        ];

        foreach ($formats as $format) {
            Format::updateOrCreate(['abbreviation' => $format['abbreviation']], $format);
        }
    }
}
