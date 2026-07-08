<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('tin', 20)->nullable()->after('code');
            $table->string('rdo_code', 10)->nullable()->after('tin');
            $table->string('registered_name')->nullable()->after('rdo_code');
            $table->string('business_style')->nullable()->after('registered_name');
            $table->text('address')->nullable()->after('business_style');
            $table->string('city', 100)->nullable()->after('address');
            $table->string('zip_code', 10)->nullable()->after('city');
            $table->string('phone', 30)->nullable()->after('zip_code');
            $table->string('email', 255)->nullable()->after('phone');
            $table->string('fiscal_year_start', 5)->default('01-01')->after('email');
            $table->string('base_currency', 3)->default('PHP')->after('fiscal_year_start');
            $table->string('industry', 100)->nullable()->default('retail')->after('base_currency');
            $table->string('vat_type', 20)->default('vat_registered')->after('industry');
            $table->decimal('default_tax_rate', 5, 2)->default(12.00)->after('vat_type');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'tin', 'rdo_code', 'registered_name', 'business_style', 'address',
                'city', 'zip_code', 'phone', 'email', 'fiscal_year_start',
                'base_currency', 'industry', 'vat_type', 'default_tax_rate',
            ]);
        });
    }
};
