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
        Schema::create('whatsapp_instance_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id')->unique();
            $table->string('sub_account_id');
            $table->timestamps();
            
            $table->index('instance_id');
            $table->index('sub_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_instance_mappings');
    }
};
