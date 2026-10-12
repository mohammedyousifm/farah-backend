<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax invoices: every paid order gets one invoice number, in sequence and without gaps
 * (INV-2026-000001). Orders paid before this migration are numbered here, oldest first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('invoice_number', 24)->nullable()->unique()->after('number');
            $table->timestamp('invoice_issued_at')->nullable()->after('invoice_number');
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last')->default(0);
        });

        $last = [];
        DB::table('orders')
            ->whereIn('status', ['confirmed', 'completed', 'partially_refunded', 'refunded'])
            ->whereNotNull('confirmed_at')
            ->orderBy('confirmed_at')->orderBy('id')
            ->select('id', 'confirmed_at')
            ->chunk(500, function ($orders) use (&$last) {
                foreach ($orders as $order) {
                    $year = (int) substr($order->confirmed_at, 0, 4);
                    $last[$year] = ($last[$year] ?? 0) + 1;

                    DB::table('orders')->where('id', $order->id)->update([
                        'invoice_number' => sprintf('INV-%d-%06d', $year, $last[$year]),
                        'invoice_issued_at' => $order->confirmed_at,
                    ]);
                }
            });

        foreach ($last as $year => $number) {
            DB::table('invoice_sequences')->insert(['year' => $year, 'last' => $number]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['invoice_number']);
            $table->dropColumn(['invoice_number', 'invoice_issued_at']);
        });
    }
};
