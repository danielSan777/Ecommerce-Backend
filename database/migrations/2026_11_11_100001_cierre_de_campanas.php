<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensaje de despedida de una campaña: al terminar la temporada se muestra `closing_message` (por ejemplo
 * "San Valentín regresará pronto") hasta el día `closing_until_md` (MM-DD, se repite cada año); después no se muestra nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('closing_message', 160)->nullable()->after('emoji');
            $table->string('closing_until_md', 5)->nullable()->after('closing_message');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['closing_message', 'closing_until_md']);
        });
    }
};
