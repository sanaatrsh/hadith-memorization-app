<?php

namespace App\Imports;

use App\Models\Book;
use App\Models\Hadith;
use App\Models\Narrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

/**
 * Chunked hadith import with per-row validation and row-level error
 * collection. Books and narrators are matched by their natural key
 * (title / name). Hadiths are always created — duplicates are never guessed
 * from free text, per the plan.
 */
class HadithsImport implements OnEachRow, WithChunkReading, WithHeadingRow
{
    public int $imported = 0;

    public int $failed = 0;

    public int $total = 0;

    /** @var array<int,array{row:int,errors:array<int,string>}> */
    public array $rowErrors = [];

    public function onRow(Row $row): void
    {
        $this->total++;
        $index = $row->getIndex();
        $data = $row->toArray();

        $validator = Validator::make($data, [
            'book_title' => ['required', 'string', 'max:255'],
            'hadith_title' => ['required', 'string', 'max:255'],
            'hadith_text' => ['required', 'string'],
            'narrator_name' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'terms_json' => ['nullable', 'string'],
            'assistance_notes' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable'],
        ]);

        if ($validator->fails()) {
            $this->recordError($index, $validator->errors()->all());

            return;
        }

        $terms = $this->parseTerms($data['terms_json'] ?? null);
        if ($terms === false) {
            $this->recordError($index, ['terms_json must be valid JSON array of {term, explanation}.']);

            return;
        }

        DB::transaction(function () use ($data, $terms) {
            $book = Book::firstOrCreate(
                ['title' => trim((string) $data['book_title'])],
                ['is_active' => true]
            );

            $narratorId = null;
            if (! empty($data['narrator_name'])) {
                $narratorId = Narrator::firstOrCreate(['name' => trim((string) $data['narrator_name'])])->id;
            }

            $hadith = Hadith::create([
                'book_id' => $book->id,
                'narrator_id' => $narratorId,
                'title' => trim((string) $data['hadith_title']),
                'text' => (string) $data['hadith_text'],
                'source' => $data['source'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => $this->parseBool($data['is_active'] ?? true),
            ]);

            foreach ($terms as $term) {
                $hadith->terms()->create($term);
            }

            if (! empty($data['assistance_notes'])) {
                $hadith->aids()->create([
                    'title' => 'ملاحظات',
                    'content' => (string) $data['assistance_notes'],
                    'sort_order' => 0,
                ]);
            }
        });

        $this->imported++;
    }

    public function chunkSize(): int
    {
        return 100;
    }

    /**
     * @return array<int,array{term:string,explanation:string}>|false
     */
    private function parseTerms(mixed $raw): array|false
    {
        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            return false;
        }

        $terms = [];
        foreach ($decoded as $item) {
            if (! is_array($item) || ! isset($item['term'], $item['explanation'])) {
                return false;
            }
            $terms[] = [
                'term' => (string) $item['term'],
                'explanation' => (string) $item['explanation'],
            ];
        }

        return $terms;
    }

    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * @param  array<int,string>  $errors
     */
    private function recordError(int $index, array $errors): void
    {
        $this->failed++;
        $this->rowErrors[] = ['row' => $index, 'errors' => $errors];
    }
}
