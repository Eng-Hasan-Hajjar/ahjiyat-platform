<?php

use App\Services\Operations\MigrationRiskScanner;

/**
 * E25 (C5) - ماسح مخاطر الترحيلات: يرصد العمليات الهدّامة/المقفلة في up() فقط، ولا يعاقب ما هو additive.
 */
function e25_migration(string $up, string $down = ''): string
{
    return "<?php\nreturn new class extends Migration {\n    public function up(): void\n    {\n        {$up}\n    }\n\n    public function down(): void\n    {\n        {$down}\n    }\n};";
}

test('E25-C5: additive migrations are not flagged', function () {
    $source = e25_migration("Schema::create('badges', function (Blueprint \$t) { \$t->id(); \$t->string('name')->nullable(); \$t->timestamps(); });\nSchema::table('users', fn (\$t) => \$t->unsignedInteger('score')->default(0));");

    expect((new MigrationRiskScanner)->scan($source))->toBe([]);
});

test('E25-C5: each dangerous operation maps to its own risk code', function (string $up, string $code, bool $breaking) {
    $scanner = new MigrationRiskScanner;

    expect($scanner->scan(e25_migration($up)))->toContain($code)
        ->and($scanner->isBreaking($code))->toBe($breaking)
        ->and($scanner->describe($code))->not->toBe($code);   // وصف عربي مقروء لا الرمز الخام
})->with([
    'drop column' => ["Schema::table('users', fn (\$t) => \$t->dropColumn('legacy'));", 'drop_column', true],
    'rename column' => ["Schema::table('users', fn (\$t) => \$t->renameColumn('a', 'b'));", 'rename_column', true],
    'rename table' => ["Schema::rename('old_t', 'new_t');", 'rename_column', true],
    'change type' => ["Schema::table('users', fn (\$t) => \$t->string('name', 50)->change());", 'change_type', true],
    'drop table' => ["Schema::dropIfExists('legacy');", 'drop_table', true],
    'drop index' => ["Schema::table('users', fn (\$t) => \$t->dropIndex('users_x_index'));", 'drop_constraint', false],
    'add index' => ["Schema::table('puzzle_attempts', fn (\$t) => \$t->index(['user_id', 'created_at']));", 'add_index', false],
    'bulk update' => ["DB::table('users')->where('x', 1)->update(['y' => 2]);", 'bulk_data', true],
    'bulk delete' => ["DB::table('users')->delete();", 'bulk_data', true],
    'raw alter' => ["DB::statement('ALTER TABLE users ADD COLUMN z INT');", 'raw_ddl', false],
    'eloquent in migration' => ["User::where('x', 1)->update(['y' => 2]);", 'eloquent_in_migration', false],
]);

test('E25-C5: only up() is scanned: destructive down() methods are normal', function () {
    $source = e25_migration("Schema::create('t', fn (\$b) => \$b->id());", "Schema::dropIfExists('t');\n        Schema::table('users', fn (\$t) => \$t->dropColumn('x'));");

    expect((new MigrationRiskScanner)->scan($source))->toBe([]);
});

test('E25-C5: a file without up() yields nothing and unknown codes degrade gracefully', function () {
    $scanner = new MigrationRiskScanner;

    expect($scanner->scan('<?php return [];'))->toBe([])
        ->and($scanner->isBreaking('nope'))->toBeFalse()
        ->and($scanner->describe('nope'))->toBe('nope');
});

test('E25-C5: a mixed migration reports every risk it contains', function () {
    $source = e25_migration("Schema::table('users', function (\$t) { \$t->dropColumn('a'); \$t->index('b'); });\nDB::table('users')->update(['b' => 1]);");

    expect((new MigrationRiskScanner)->scan($source))->toEqualCanonicalizing(['drop_column', 'add_index', 'bulk_data']);
});
