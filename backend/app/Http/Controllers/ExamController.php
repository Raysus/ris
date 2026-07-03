<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksRisAuthorization;
use App\Models\Exam;
use App\Support\ModalityCode;
use App\Models\ExamInstruction;
use App\Services\ExamSubExamService;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    use ChecksRisAuthorization;

    public function index(Request $request)
    {
        $query = Exam::query();

        $allowedLabs = config('app.allowed_lab_ids');

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('laboratory_id', $allowedLabs);
            }
        }

        $data = $query->with(['instruction', 'subExams', 'tariffs'])->orderBy('name')->get()->map(function (Exam $exam) {
            $arr = $exam->toArray();
            $arr['sub_exams'] = ExamSubExamService::serializeForAgenda($exam);

            return $arr;
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(Request $request)
    {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'id' => 'nullable|string',
            'group_code' => 'required|string',
            'name' => 'required|string',
            'fonasa_code' => 'nullable|string',
            'price' => 'nullable|numeric',
            'sub_exams' => 'nullable|array',
            'instruction' => 'nullable|array',
            'instruction.body' => 'nullable|string',
            'instruction.subject' => 'nullable|string|max:255',
            'instruction.is_active' => 'nullable|boolean',
        ]);

        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');

        if (!$labId) {
            return response()->json(['success' => false, 'message' => 'Debe seleccionar un laboratorio.'], 400);
        }

        $payload = [
            'laboratory_id' => $labId,
            'group_code' => ModalityCode::normalizeGroup($validated['group_code']),
            'name' => $validated['name'],
            'sub_exams' => [],
            'fonasa_code' => $validated['fonasa_code'] ?? null,
            'price' => $validated['price'] ?? 0,
            'is_active' => true,
        ];

        if (!empty($validated['id'])) {
            $existing = Exam::query()
                ->where('id', $validated['id'])
                ->where('laboratory_id', $labId)
                ->first();
            if (!$existing) {
                return response()->json(['success' => false, 'message' => 'Examen no encontrado en este laboratorio.'], 404);
            }
            $exam = Exam::updateOrCreate(['id' => $validated['id']], $payload);
        } else {
            $exam = Exam::updateOrCreate(
                ['laboratory_id' => $labId, 'name' => $validated['name']],
                $payload
            );
        }

        ExamSubExamService::syncFromItems($exam, $validated['sub_exams'] ?? []);

        $this->syncExamInstruction($exam, $validated['instruction'] ?? null);

        $exam->load(['instruction', 'subExams']);
        $examPayload = $exam->toArray();
        $examPayload['sub_exams'] = ExamSubExamService::serializeForAgenda($exam);

        return response()->json(['success' => true, 'exam' => $examPayload]);
    }

    public function update(Request $request, string $id)
    {
        $request->merge(['id' => $id]);

        return $this->store($request);
    }

    private function syncExamInstruction(Exam $exam, ?array $instruction): void
    {
        if ($instruction === null) {
            return;
        }

        $body = trim($instruction['body'] ?? '');

        if ($body === '') {
            ExamInstruction::where('exam_id', $exam->id)->delete();
            return;
        }

        ExamInstruction::updateOrCreate(
            ['exam_id' => $exam->id],
            [
                'subject' => $instruction['subject'] ?? null,
                'body' => $body,
                'is_active' => array_key_exists('is_active', $instruction)
                    ? (bool) $instruction['is_active']
                    : true,
            ]
        );
    }

    public function importExams(Request $request)
    {
        $this->assertAdmin($request);
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv,txt'
        ]);

        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');
        if (!$labId) {
            return response()->json(['success' => false, 'message' => 'Laboratorio no identificado.'], 400);
        }

        $file = $request->file('file');
        $importedCount = 0;

        if (($handle = fopen($file->getRealPath(), "r")) !== FALSE) {
            $bom = fread($handle, 3);
            if ($bom != "\xEF\xBB\xBF") {
                rewind($handle);
            }

            fgetcsv($handle, 1000, ";");

            while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                // Verificar que la fila no esté vacía y tenga al menos 4 columnas
                if (count($data) < 4 || trim($data[0]) === '') {
                    continue;
                }

                $exam = Exam::updateOrCreate(
                    [
                        'laboratory_id' => $labId,
                        'name' => trim($data[1])
                    ],
                    [
                        'group_code' => ModalityCode::normalizeGroup(trim($data[0])),
                        'fonasa_code' => trim($data[2]),
                        'price' => floatval(trim($data[3])) ?: 0,
                        'sub_exams' => []
                    ]
                );
                $importedCount++;
            }
            fclose($handle);
        }

        return response()->json([
            'success' => true,
            'imported' => $importedCount,
            'message' => 'Importación finalizada correctamente.'
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->assertAdmin($request);
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Exam::query()->where('id', $id);

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
            if (empty($allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Examen no encontrado'], 404);
            }
            $query->whereIn('laboratory_id', $allowedLabs);
        }

        $exam = $query->first();

        if ($exam) {
            $exam->delete();
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Examen no encontrado'], 404);
    }
}