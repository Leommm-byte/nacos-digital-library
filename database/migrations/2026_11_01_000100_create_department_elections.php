<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elections for other departments (docs/department-elections.md): each
 * department NACOS approves gets its own space at /vote/{slug}, with its
 * own admins, nominal roll, voters and elections. Nothing here touches the
 * NACOS app's users or nominal roll.
 *
 * - organizations: the departments, set up by a NACOS admin.
 * - voters: accounts for one department's elections (students, and the
 *   department's own admins). Separate from users: a student who is also
 *   in NACOS has two accounts, one for each.
 * - organization_roll: each department's nominal roll.
 * - organization_election_voters: who voted, like election_voters, with no
 *   link to election_votes, so ballots stay secret.
 * - elections.organization_id: null for NACOS's own elections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('short_name', 30)->nullable();
            $table->string('slug', 60)->unique();
            $table->string('status', 16)->default('active');
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->text('notes')->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });

        Schema::create('voters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('fullname', 150);
            // Admins of a department may have no matric number; students
            // sign in with theirs.
            $table->string('matric_number', 32)->nullable();
            $table->unsignedSmallInteger('entry_year')->nullable();
            $table->string('email')->nullable();
            $table->string('role', 16)->default('voter');
            $table->string('status', 16)->default('active');
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->unique(['organization_id', 'matric_number']);
            $table->unique(['organization_id', 'email']);
            $table->index(['organization_id', 'role']);
        });

        Schema::create('organization_roll', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('matric_number', 32);
            $table->string('fullname', 150)->nullable();
            $table->string('email')->nullable();
            $table->string('level', 8)->nullable();
            $table->string('programme', 16)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'matric_number']);
            $table->index(['organization_id', 'programme', 'level']);
        });

        Schema::table('elections', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::create('organization_election_voters', function (Blueprint $table) {
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voter_id')->constrained()->cascadeOnDelete();
            $table->timestamp('voted_at')->useCurrent();

            $table->primary(['election_id', 'voter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_election_voters');
        Schema::table('elections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::dropIfExists('organization_roll');
        Schema::dropIfExists('voters');
        Schema::dropIfExists('organizations');
    }
};
