<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3)->default('out');
            $table->string('service', 30)->index();      // admin_api, cashfree, s3, ...
            $table->string('method', 10);
            $table->string('url', 2048);
            $table->string('route', 255)->nullable();
            $table->string('user_public_id', 32)->nullable()->index();
            $table->json('request_headers')->nullable();  // sensitive values already redacted
            $table->longText('request_body')->nullable(); // redacted / masked
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable(); // redacted / masked
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
