<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * One row per call to SATIM: what was sent, what came back, and what it meant.
 *
 * Rows are written before the request leaves and completed when the answer
 * arrives, so a call that times out still leaves evidence. Nothing here belongs
 * to the host application: only SATIM's own identifiers and payloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            // Correlates the row with the events that created and completed it.
            //
            // A plain string rather than a uuid column: SQL Server's
            // uniqueidentifier type rewrites the value in upper case, so the id
            // read back would not match the one the event carried.
            $table->string('call_id', 36)->unique();

            $table->string('operation', 20)->index();

            // Present on register; SATIM's own id arrives with the response.
            $table->string('order_number', 20)->nullable()->index();
            $table->string('order_id', 40)->nullable()->index();

            $table->unsignedBigInteger('amount_in_centimes')->nullable();

            // json rather than jsonb, so the table works on every database
            // Laravel supports.
            $table->json('request');
            $table->json('response')->nullable();

            // Null until the call finishes: the meaningful outcome, not merely
            // whether a reply arrived.
            $table->boolean('successful')->nullable();

            $table->string('error_code', 10)->nullable();
            $table->text('error_message')->nullable();
            $table->smallInteger('order_status')->nullable();

            // Set only when no usable reply arrived at all.
            $table->text('failure_reason')->nullable();

            $table->timestamp('created_at');
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return Config::string('satim.audit.table', 'satim_calls');
    }
};
