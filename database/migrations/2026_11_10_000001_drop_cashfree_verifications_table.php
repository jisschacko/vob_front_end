<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The UPI-scan (Reverse Penny Drop) bank verification is gone: its temporary table is no longer used.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cashfree_verifications');
    }

    public function down(): void
    {
        Schema::create('cashfree_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('verification_id')->unique();
            $table->string('tot_ref')->index();
            $table->string('status')->default('CREATED');
            $table->string('expected_fingerprint', 64);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
};
