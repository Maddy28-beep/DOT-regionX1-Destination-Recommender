<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One `users` table for everyone who signs in to the portals (admins and establishments), with `role` telling them apart.
 * What only a business has (name, kind of listing, accreditation claim, review of the sign-up) lives in `establishment_profiles`.
 *
 * Every id is kept (they were UUIDs already), so notifications, audit rows, accreditation checks and submissions keep pointing
 * at the same person. Travelers stay out on purpose: their account is an alias and a password and nothing more.
 */
return new class extends Migration
{
    private const REPOINT = [
        // table, column, on delete
        ['accreditation_records', 'verified_by', 'null'],
        ['system_audit_logs', 'admin_id', 'cascade'],
        ['advisories', 'admin_id', 'null'],
        ['listing_submissions', 'establishment_account_id', 'cascade'],
    ];

    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('role', 30)->index();            // super_admin, … or establishment
            $table->string('name', 150)->nullable();
            $table->string('email', 255)->unique();
            $table->string('password_hash', 255);
            $table->string('status', 20)->nullable();       // establishments: pending, approved, rejected
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('establishment_profiles', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('business_name', 150);
            $table->string('listing_kind', 30);
            $table->string('claimed_accreditation_number', 50)->nullable();
            $table->string('certificate_file_name', 255)->nullable();
            $table->unsignedBigInteger('matched_listing_id')->nullable();
            $table->string('portal_key', 120)->unique();
            $table->string('contact_number', 20);
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->index(['listing_kind', 'matched_listing_id']);
        });

        $taken = [];
        foreach (DB::table('admin_users')->get() as $a) {
            $taken[mb_strtolower($a->email)] = true;
            DB::table('users')->insert(['id' => $a->id, 'role' => $a->role, 'name' => $a->full_name, 'email' => $a->email, 'password_hash' => $a->password_hash, 'status' => null, 'created_at' => $a->created_at]);
        }
        foreach (DB::table('establishment_accounts')->get() as $e) {
            if (isset($taken[mb_strtolower($e->email)])) {
                throw new RuntimeException("The email {$e->email} belongs to an admin and an establishment. Change one of them before merging the accounts.");
            }
            DB::table('users')->insert(['id' => $e->id, 'role' => 'establishment', 'name' => $e->contact_person, 'email' => $e->email, 'password_hash' => $e->password_hash, 'status' => $e->status, 'created_at' => $e->submitted_at]);
        }
        // Profiles after every user exists, so reviewed_by can point at an admin.
        foreach (DB::table('establishment_accounts')->get() as $e) {
            DB::table('establishment_profiles')->insert([
                'user_id' => $e->id, 'business_name' => $e->business_name, 'listing_kind' => $e->listing_kind, 'claimed_accreditation_number' => $e->claimed_accreditation_number,
                'certificate_file_name' => $e->certificate_file_name, 'matched_listing_id' => $e->matched_listing_id, 'portal_key' => $e->portal_key, 'contact_number' => $e->contact_number,
                'submitted_at' => $e->submitted_at, 'reviewed_by' => $e->reviewed_by, 'reviewed_at' => $e->reviewed_at, 'review_note' => $e->review_note,
            ]);
        }

        foreach (self::REPOINT as [$table, $column, $onDelete]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign([$column]));
            Schema::table($table, function (Blueprint $t) use ($column, $onDelete) {
                $fk = $t->foreign($column)->references('id')->on('users');
                $onDelete === 'cascade' ? $fk->cascadeOnDelete() : $fk->nullOnDelete();
            });
        }

        Schema::drop('establishment_accounts');
        Schema::drop('admin_users');
    }

    public function down(): void
    {
        throw new RuntimeException('Merging the accounts into users cannot be undone here. Restore the database backup taken before migrating.');
    }
};
