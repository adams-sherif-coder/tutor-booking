<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('student')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->text('bio')->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
        });
        Schema::create('subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('subject_user', function (Blueprint $table): void {
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['subject_id', 'user_id']);
            $table->timestamps();
        });
        Schema::create('availabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tutor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();
            $table->index(['tutor_id', 'starts_at', 'ends_at']);
        });
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tutor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('pending');
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('total_price', 12, 2);
            $table->timestamps();
            $table->index(['tutor_id', 'status', 'starts_at', 'ends_at']);
            $table->index(['student_id', 'status', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('availabilities');
        Schema::dropIfExists('subject_user');
        Schema::dropIfExists('subjects');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['role', 'is_active', 'bio', 'hourly_rate']);
        });
    }
};
