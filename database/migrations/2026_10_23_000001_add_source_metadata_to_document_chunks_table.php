<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            // PDF page(s) the chunk's text came from, so answers can cite them.
            $table->unsignedSmallInteger('page_start')->nullable()->after('chunk_index');
            $table->unsignedSmallInteger('page_end')->nullable()->after('page_start');
            // Heading path such as "Chapter 2 > South Asia > Outlook".
            $table->string('section_path')->nullable()->after('page_end');
            // What kind of content the chunk holds: text, table, chart, footnote.
            $table->string('chunk_type')->default('text')->after('section_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropColumn(['page_start', 'page_end', 'section_path', 'chunk_type']);
        });
    }
};
