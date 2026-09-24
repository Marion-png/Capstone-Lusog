<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The photographed paper form a consent was recorded from.
 *
 * Most consents come back through the parent link, signed on a screen. Some
 * come back the way they always have: on paper, in a child's bag, signed in
 * ink. Until now the second kind had no way into the system at all — the
 * consent half of the document is editable only through the parent's own link.
 *
 * When the adviser records one from paper, the photograph is the evidence that
 * a parent actually agreed: the signature column holds a drawn PNG and there
 * is none here, so the sheet itself has to be retrievable from the record or
 * the consent is an assertion with nothing behind it.
 *
 * The path is plain — it says nothing about a child. The file it names is
 * written through App\Support\EncryptedFileStorage like every other uploaded
 * document, and is served only to the roles that may already read the form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_consent_forms', function (Blueprint $table) {
            if (! Schema::hasColumn('health_consent_forms', 'paper_form_path')) {
                $table->string('paper_form_path')->nullable()->after('signature');
            }
        });
    }

    public function down(): void
    {
        Schema::table('health_consent_forms', function (Blueprint $table) {
            $table->dropColumn('paper_form_path');
        });
    }
};
