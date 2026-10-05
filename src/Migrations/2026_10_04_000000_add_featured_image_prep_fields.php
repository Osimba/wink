<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFeaturedImagePrepFields extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('wink_posts', function (Blueprint $table) {
            $table->string('featured_image_original')->nullable()->after('featured_image_caption');
            $table->string('featured_image_source_url', 2048)->nullable()->after('featured_image_original');
            $table->string('featured_image_credit')->nullable()->after('featured_image_source_url');
            $table->string('featured_image_alt')->nullable()->after('featured_image_credit');
            $table->json('featured_image_meta')->nullable()->after('featured_image_alt');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('wink_posts', function (Blueprint $table) {
            $table->dropColumn([
                'featured_image_original',
                'featured_image_source_url',
                'featured_image_credit',
                'featured_image_alt',
                'featured_image_meta',
            ]);
        });
    }
}
