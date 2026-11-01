<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Department election spaces, phase 2 (docs/department-elections.md):
 * each department's colour, and requests from departments that want a
 * space, for a NACOS admin to approve or decline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // A key from App\Support\Departments\Colours; null is NACOS green.
            $table->string('colour', 16)->nullable()->after('logo_path');
        });

        Schema::create('organization_requests', function (Blueprint $table) {
            $table->id();
            $table->string('department_name', 150);
            $table->string('contact_name', 150);
            $table->string('contact_role', 100)->nullable();
            $table->string('contact_email');
            $table->string('contact_phone', 40)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_requests');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('colour');
        });
    }
};
