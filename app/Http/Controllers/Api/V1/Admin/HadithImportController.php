<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHadithImportRequest;
use App\Imports\HadithsImport;
use App\Models\HadithImport;
use App\Support\ApiResponse;
use Maatwebsite\Excel\Facades\Excel;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HadithImportController extends Controller
{
    private const TEMPLATE_COLUMNS = [
        'book_title', 'hadith_title', 'hadith_text', 'narrator_name',
        'source', 'terms_json', 'assistance_notes', 'sort_order', 'is_active',
    ];

    #[OA\Post(
        path: '/admin/imports/hadiths',
        operationId: 'importHadiths',
        summary: 'Import hadiths from a spreadsheet (admin)',
        description: 'Upload XLSX, XLS, or CSV (max 10 MB). Books/narrators are matched by name; hadiths are always created. Returns per-row errors. Rate limited to 5 requests/minute.',
        tags: ['Admin - Imports'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', description: 'XLSX, XLS, or CSV. Maximum 10 MB.', type: 'string', format: 'binary'),
                    ],
                    type: 'object',
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Import processed.', content: new OA\JsonContent(ref: '#/components/schemas/ImportResult')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Invalid file.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
            new OA\Response(response: 429, description: 'Too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitError')),
        ],
    )]
    public function store(StoreHadithImportRequest $request)
    {
        $file = $request->file('file');

        $import = new HadithsImport;
        Excel::import($import, $file);

        $status = match (true) {
            $import->imported === 0 && $import->failed > 0 => 'failed',
            $import->failed > 0 => 'completed_with_errors',
            default => 'completed',
        };

        $record = HadithImport::create([
            'admin_id' => $request->user()->id,
            'original_filename' => $file->getClientOriginalName(),
            'status' => $status,
            'total_rows' => $import->total,
            'imported_rows' => $import->imported,
            'failed_rows' => $import->failed,
            'errors' => $import->rowErrors,
        ]);

        return ApiResponse::success([
            'import_id' => $record->id,
            'status' => $record->status,
            'total_rows' => $record->total_rows,
            'imported_rows' => $record->imported_rows,
            'failed_rows' => $record->failed_rows,
            'errors' => $record->errors,
        ], 'Import processed.', 201);
    }

    #[OA\Get(
        path: '/admin/imports/{import}',
        operationId: 'showImport',
        summary: 'Get an import result (admin)',
        tags: ['Admin - Imports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'import', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Import record.', content: new OA\JsonContent(ref: '#/components/schemas/ImportResult')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function show(HadithImport $import)
    {
        return ApiResponse::success([
            'import_id' => $import->id,
            'original_filename' => $import->original_filename,
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'imported_rows' => $import->imported_rows,
            'failed_rows' => $import->failed_rows,
            'errors' => $import->errors,
            'created_at' => $import->created_at,
        ], 'Import retrieved successfully.');
    }

    #[OA\Get(
        path: '/admin/imports/hadiths/template',
        operationId: 'downloadImportTemplate',
        summary: 'Download the hadith import CSV template (admin)',
        tags: ['Admin - Imports'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'CSV template file.', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
        ],
    )]
    public function template(): StreamedResponse
    {
        $filename = 'hadith_import_template.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens Arabic correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::TEMPLATE_COLUMNS);
            fputcsv($out, [
                'رياض الصالحين',
                'إنما الأعمال بالنيات',
                'إنما الأعمال بالنيات وإنما لكل امرئ ما نوى',
                'عمر بن الخطاب',
                'صحيح البخاري',
                '[{"term":"النية","explanation":"القصد"}]',
                'ركز على الكلمة الأولى',
                '1',
                'true',
            ]);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
