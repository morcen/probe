<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probe_entry_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entry_id');
            $table->string('tag', 191);

            // Leading entry_id lets prune()/clear() look up (and the FK
            // cascade delete) a row's tags without a table scan; leading tag
            // is what makes whereHasTag() an indexed lookup instead of the
            // leading-wildcard LIKE scan this table replaces.
            $table->unique(['entry_id', 'tag']);
            $table->index('tag');

            $table->foreign('entry_id')->references('id')->on('probe_entries')->cascadeOnDelete();
        });

        // Backfill existing installs: probe_entries.tags stays the source of
        // truth for display, but every comma-separated value it already
        // holds needs a matching row here so whereHasTag() keeps finding
        // entries tagged before this migration ran.
        DB::table('probe_entries')
            ->whereNotNull('tags')
            ->where('tags', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                $inserts = [];

                foreach ($rows as $row) {
                    foreach (array_filter(array_unique(explode(',', $row->tags))) as $tag) {
                        $inserts[] = ['entry_id' => $row->id, 'tag' => $tag];
                    }
                }

                if (! empty($inserts)) {
                    DB::table('probe_entry_tags')->insertOrIgnore($inserts);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('probe_entry_tags');
    }
};
