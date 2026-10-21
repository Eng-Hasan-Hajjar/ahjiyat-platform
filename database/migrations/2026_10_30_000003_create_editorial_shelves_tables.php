<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E35 - الأرفف التحريرية (Editorial Shelves): قوائم يختارها فريق المنصة يدويًا لمواضع محدودة (اكتشاف/رئيسية).
|
|  editorial_shelves        هوية عامة ULID + مفتاح داخلي فريد + موضع من قائمة مغلقة + حالة (draft|active|archived) + جدولة اختيارية.
|  editorial_shelf_items    (رف، نوع محتوى من قائمة مغلقة، معرّف المحتوى) فريد + موضع صريح.
|
| لا تُخزَّن هنا أسماء أصناف PHP ولا مسارات ولا URL ولا HTML: النوع من قائمة مغلقة في EditorialContentType والمعرّف رقم مفتاح أساسي لكيان موجود،
| ويُحلّ كل ذلك وقت القراءة بدفعة واحدة لكل نوع مع إعادة فحص أهلية الظهور (المحتوى المخفي/المؤرشف/المعلّق يختفي من الرف فورًا).
| المحتوى المحذوف لا يكسر الرف: تُتجاهل الصفوف غير القابلة للحل وقت القراءة.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_shelves', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('internal_key', 64)->unique();
            $table->string('title', 80);
            $table->string('subtitle', 160)->nullable();
            $table->string('placement', 16);
            $table->string('status', 16)->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('max_items')->default(8);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['placement', 'status', 'sort_order'], 'editorial_shelves_placement_index');
        });

        Schema::create('editorial_shelf_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('editorial_shelf_id')->constrained()->cascadeOnDelete();
            $table->string('content_type', 24);
            $table->unsignedBigInteger('content_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['editorial_shelf_id', 'content_type', 'content_id'], 'shelf_items_content_unique');
            $table->index(['editorial_shelf_id', 'position'], 'shelf_items_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_shelf_items');
        Schema::dropIfExists('editorial_shelves');
    }
};
