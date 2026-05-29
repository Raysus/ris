<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('machines')) {
            DB::table('machines')->where('group', 'ECO')->update(['group' => 'US']);
        }
        if (Schema::hasTable('exams')) {
            DB::table('exams')->where('group_code', 'ECO')->update(['group_code' => 'US']);
        }
        if (Schema::hasTable('report_templates')) {
            DB::table('report_templates')->where('group_code', 'ECO')->update(['group_code' => 'US']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('machines')) {
            DB::table('machines')->where('group', 'US')->update(['group' => 'ECO']);
        }
        if (Schema::hasTable('exams')) {
            DB::table('exams')->where('group_code', 'US')->update(['group_code' => 'ECO']);
        }
        if (Schema::hasTable('report_templates')) {
            DB::table('report_templates')->where('group_code', 'US')->update(['group_code' => 'ECO']);
        }
    }
};
