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
        Schema::table('documents', function (Blueprint $table) {
            // How the document is processed: narrative, structured or visual.
            $table->string('profile')->nullable()->after('status');
            $table->unsignedSmallInteger('page_count')->nullable()->after('profile');
        });

        // Keyword search over chunk text and section titles, used alongside
        // vector search ("hybrid search"). SQLite (used by the test suite)
        // has no FULLTEXT indexes; keyword search is skipped there.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            Schema::table('document_chunks', function (Blueprint $table) {
                $table->fullText(['content', 'section_path']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            Schema::table('document_chunks', function (Blueprint $table) {
                $table->dropFullText(['content', 'section_path']);
            });
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['profile', 'page_count']);
        });
    }
};
