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
        Schema::create('importer_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('user_id');
            $table->string('status');
            $table->string('protocol');
            $table->string('source_path');
            $table->string('destination_path');
            $table->unsignedBigInteger('bytes_transferred')->default(0);
            $table->unsignedBigInteger('bytes_total')->default(0);
            $table->unsignedBigInteger('speed')->default(0);
            $table->integer('eta')->nullable();
            $table->string('checksum_expected')->nullable();
            $table->string('checksum_actual')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('importer_transfers');
    }
};
