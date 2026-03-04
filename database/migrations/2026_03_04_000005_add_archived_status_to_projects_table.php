<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status::text = ANY (ARRAY['pending'::text, 'approved'::text, 'rejected'::text, 'in_progress'::text, 'completed'::text, 'failed'::text, 'awaiting_validation'::text, 'archived'::text]))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status::text = ANY (ARRAY['pending'::text, 'approved'::text, 'rejected'::text, 'in_progress'::text, 'completed'::text, 'failed'::text, 'awaiting_validation'::text]))");
    }
};
