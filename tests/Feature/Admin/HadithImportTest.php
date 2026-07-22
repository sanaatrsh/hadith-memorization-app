<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HadithImportTest extends TestCase
{
    use RefreshDatabase;

    private function csvUpload(string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $contents);

        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }

    public function test_valid_rows_are_imported(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $csv = "book_title,hadith_title,hadith_text,narrator_name,source,terms_json,assistance_notes,sort_order,is_active\n"
            ."رياض الصالحين,عنوان,نص الحديث,راوي,مصدر,\"[{\"\"term\"\":\"\"ن\"\",\"\"explanation\"\":\"\"ق\"\"}]\",ملاحظة,1,true\n";

        $this->postJson('/api/v1/admin/imports/hadiths', ['file' => $this->csvUpload($csv)])
            ->assertCreated()
            ->assertJsonPath('data.imported_rows', 1)
            ->assertJsonPath('data.failed_rows', 0);

        $this->assertDatabaseHas('hadiths', ['title' => 'عنوان']);
        $this->assertDatabaseHas('books', ['title' => 'رياض الصالحين']);
        $this->assertDatabaseHas('hadith_terms', ['term' => 'ن']);
    }

    public function test_invalid_rows_return_row_level_errors(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        // Second row is missing hadith_text.
        $csv = "book_title,hadith_title,hadith_text,narrator_name,source,terms_json,assistance_notes,sort_order,is_active\n"
            ."كتاب,عنوان1,نص,راوي,مصدر,,,1,true\n"
            ."كتاب,عنوان2,,راوي,مصدر,,,2,true\n";

        $response = $this->postJson('/api/v1/admin/imports/hadiths', ['file' => $this->csvUpload($csv)])
            ->assertCreated()
            ->assertJsonPath('data.imported_rows', 1)
            ->assertJsonPath('data.failed_rows', 1)
            ->assertJsonPath('data.status', 'completed_with_errors');

        $this->assertNotEmpty($response->json('data.errors'));
    }

    public function test_template_can_be_downloaded(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->get('/api/v1/admin/imports/hadiths/template');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_import_record_can_be_retrieved(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $csv = "book_title,hadith_title,hadith_text,narrator_name,source,terms_json,assistance_notes,sort_order,is_active\n"
            ."كتاب,عنوان,نص,راوي,مصدر,,,1,true\n";

        $importId = $this->postJson('/api/v1/admin/imports/hadiths', ['file' => $this->csvUpload($csv)])
            ->json('data.import_id');

        $this->getJson("/api/v1/admin/imports/{$importId}")
            ->assertOk()
            ->assertJsonPath('data.import_id', $importId);
    }
}
