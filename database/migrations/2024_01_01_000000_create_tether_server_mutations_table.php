<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('tether-server.table', 'tether_server_mutations');
        $connection = config('tether-server.connection');

        Schema::connection($connection)->create($table, function (Blueprint $table) {
            $table->id(); // auto-increment - doubles as the monotonic sync cursor
            $table->char('mutation_id', 26)->unique();
            $table->char('entity_id', 26)->index();
            $table->string('model');
            $table->string('operation');
            $table->json('payload');
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('timestamp'); // original client mutation timestamp (ms)
            $table->string('client_id')->index();
            $table->timestamp('applied_at')->useCurrent();
        });
    }

    public function down(): void
    {
        $table = config('tether-server.table', 'tether_server_mutations');
        $connection = config('tether-server.connection');

        Schema::connection($connection)->dropIfExists($table);
    }
};
