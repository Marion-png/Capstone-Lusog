<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clinic's paper logbook has two halves, and the stock record only had one.
 *
 * Medicine went OUT through the consultation dialog (medicine_dispenses), but
 * nothing ever came IN: a stock level was typed when an item was created and
 * could only fall from there, so a delivery had no way onto the record and the
 * count drifted from the shelf the moment the first box arrived. And nothing
 * held an expiry date at all, so a shelf full of paracetamol could be counted
 * as good stock a year after it should have been pulled.
 *
 * Two additions:
 *
 *  - `medicines.expiry_date` — the earliest expiry of the stock on hand, which
 *    is the date the whole item is judged by: a box that expires first is the
 *    box that gets used first, and when it goes the next one's date takes over.
 *    Plain, because a date on a box is not personal information.
 *
 *  - `medicine_receipts` — one row per delivery: how much, from where, with
 *    what expiry, received by whom. The receipt and the stock increment are
 *    written together in one transaction (MedicineInventoryController::receive),
 *    so the log and the level cannot disagree. The receiver's name is a staff
 *    name and is encrypted like `dispensed_by_name`; quantity, dates, the
 *    medicine and the school stay plain because they are what the log is
 *    filtered and summed on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            if (! Schema::hasColumn('medicines', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('unit');
            }
        });

        if (Schema::hasTable('medicine_receipts')) {
            return;
        }

        Schema::create('medicine_receipts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('quantity');
            $table->date('expiry_date')->nullable();
            $table->date('received_at');

            // Where it came from — the Division, a donation, a purchase. Not
            // personal information; a short label the log can be read by.
            $table->string('source', 120)->nullable();
            $table->string('notes', 255)->nullable();

            // Who signed for it. The role is plain so the log can be filtered
            // by desk; the name is personal information and is encrypted.
            $table->text('received_by_name')->nullable();
            $table->string('received_by_role', 40)->nullable();

            $table->timestamps();

            // The log is read newest-first within a school, and per item.
            $table->index(['institution_id', 'received_at']);
            $table->index(['medicine_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_receipts');

        Schema::table('medicines', function (Blueprint $table) {
            $table->dropColumn('expiry_date');
        });
    }
};
