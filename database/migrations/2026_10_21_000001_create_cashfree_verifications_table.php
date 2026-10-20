<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Temporary bookkeeping for a bank verification in flight. Never holds the account number: only its last four digits and a keyed hash.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashfree_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('verification_id', 50)->unique();   // our id sent to Cashfree
            $table->string('ref_id', 40)->nullable();          // Cashfree's reference
            $table->string('tot_ref', 32)->index();            // Admin public id of the TOT
            $table->string('user_public_id', 32)->nullable();
            $table->string('status', 20)->default('CREATED');  // CREATED, SUCCESS, FAILURE, EXPIRED, MISMATCH
            $table->string('name_match_result', 40)->nullable();
            $table->string('account_last4', 4)->nullable();
            $table->string('expected_fingerprint', 64);        // HMAC of account|ifsc, compared with what Cashfree reports
            $table->text('payload')->nullable();               // upi links + QR for the page (expires with the row)
            $table->string('reported_status', 10)->nullable(); // verified | failed | skipped, once sent to the Admin
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashfree_verifications');
    }
};
