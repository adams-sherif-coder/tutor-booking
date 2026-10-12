<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Mathematics', 'Physics', 'Chemistry', 'English', 'Programming', 'Arabic'] as $name) {
            Subject::firstOrCreate(['name' => $name]);
        }
    }
}
