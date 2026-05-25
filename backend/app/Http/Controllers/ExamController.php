<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamInstruction;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function index(Request $request)
    {
        $query = Exam::query();

        $allowedLabs = config('app.allowed_lab_ids');

        if ($allowedLabs !== ['*']) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('laboratory_id', $allowedLabs);
            }
        }

        $data = $query->with('instruction')->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(Request $request)
    {
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

        $exam = Exam::updateOrCreate(
            ['id' => $validated['id'] ?? null],
            [
                'laboratory_id' => $labId,
                'group_code' => strtoupper($validated['group_code']),
                'name' => $validated['name'],
                'sub_exams' => $validated['sub_exams'] ?? [],
                'fonasa_code' => $validated['fonasa_code'] ?? null,
                'price' => $validated['price'] ?? 0,
                'is_active' => true
            ]
        );

        $this->syncExamInstruction($exam, $validated['instruction'] ?? null);

        $exam->load('instruction');

        // === ☁️ INICIO SINCRONIZACIÓN CON LA NUBE (VÍA REDIS) ☁️ ===
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Exam', 'updated', $exam->toArray());
        // === FIN SINCRONIZACIÓN ===

        return response()->json(['success' => true, 'exam' => $exam]);
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
                        'group_code' => trim($data[0]),
                        'fonasa_code' => trim($data[2]),
                        'price' => floatval(trim($data[3])) ?: 0,
                        'sub_exams' => []
                    ]
                );
                $importedCount++;

                \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Exam', 'updated', $exam->toArray());
            }
            fclose($handle);
        }

        return response()->json([
            'success' => true,
            'imported' => $importedCount,
            'message' => 'Importación finalizada correctamente.'
        ]);
    }

    public function destroy($id)
    {
        $exam = Exam::find($id);

        if ($exam) {
            $exam->delete();
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Examen no encontrado'], 404);
    }
}