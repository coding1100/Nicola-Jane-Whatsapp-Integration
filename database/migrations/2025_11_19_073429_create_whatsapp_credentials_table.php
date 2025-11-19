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
        Schema::create('whatsapp_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('sub_account_id')->unique();
            $table->string('instance_id');
            $table->string('api_token');
            $table->timestamps();
            
            $table->index('sub_account_id');
            $table->index('instance_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_credentials');
    }
};
