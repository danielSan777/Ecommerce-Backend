<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un registro por compra y plataforma (GA4, Meta, TikTok): qué se envió, cuándo y qué respondió la plataforma.
        Schema::create('tracking_events', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20);
            $table->string('event', 40);
            $table->string('event_id', 100);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('status', 12)->default('pending');   // pending, sent, failed, skipped
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['platform', 'event_id']);
            $table->index(['status', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
    }
};
