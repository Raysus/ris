<?php

namespace App\Http\Controllers;

use App\Support\ModalityCode;
use App\Services\WordTemplateImportService;
use Illuminate\Http\Request;
use App\Models\ReportTemplate;

class TemplateController extends Controller
{
    private function getSecureTemplateQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = ReportTemplate::query();

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($q) use ($allowedLabs) {
                    $q->whereIn('laboratory_id', $allowedLabs)
                        ->orWhereNull('laboratory_id');
                });
            }
        }
        return $query;
    }

    public function index(Request $request)
    {
        $templates = $this->getSecureTemplateQuery()
            ->orderBy('group_code', 'asc')
            ->orderBy('title', 'asc')
            ->get();

        return response()->json(['success' => true, 'data' => $templates]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|string',
            'group_code' => 'required|string',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $user = $request->user();
        $isSysAdmin = ($user->tipoUsuario->name === 'sis_admin');
        $allowedLabs = config('app.allowed_lab_ids');
        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');

        $templateId = $validated['id'] ?? null;

        if ($templateId) {
            $template = ReportTemplate::findOrFail($templateId);

            if (is_null($template->laboratory_id) && !$isSysAdmin) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado. No puede modificar plantillas globales del sistema.'], 403);
            }

            if (!is_null($template->laboratory_id) && !\App\Models\Laboratory::allowsAllLabs($allowedLabs) && !in_array($template->laboratory_id, $allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado. Esta plantilla pertenece a otra sucursal.'], 403);
            }

        } else {
            $template = new ReportTemplate();

            if ($isSysAdmin && (!$labId || $labId === 'ALL')) {
                $template->laboratory_id = null;
            } else {
                if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs) && !in_array($labId, $allowedLabs)) {
                    return response()->json(['success' => false, 'message' => 'No tiene permisos para crear plantillas en esta sucursal.'], 403);
                }
                $template->laboratory_id = $labId;
            }
        }

        $template->group_code = ModalityCode::normalizeGroup($validated['group_code']);
        $template->title = $validated['title'];
        $template->content = $validated['content'];
        $template->save();
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\ReportTemplate', 'updated', $template->toArray());
        return response()->json(['success' => true, 'data' => $template]);
    }

    public function importFromWord(Request $request, WordTemplateImportService $wordImport)
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:docx,doc',
        ]);

        $file = $request->file('file');

        try {
            $content = $wordImport->extractPlainText($file);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo procesar el archivo Word.',
            ], 422);
        }

        $meta = $wordImport->suggestMetadataFromFilename($file->getClientOriginalName());

        return response()->json([
            'success' => true,
            'data' => [
                'title' => $meta['title'],
                'group_code' => $meta['group_code'],
                'content' => $content,
            ],
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $isSysAdmin = ($user->tipoUsuario->name === 'sis_admin');
        $allowedLabs = config('app.allowed_lab_ids');

        $template = ReportTemplate::findOrFail($id);

        if (is_null($template->laboratory_id) && !$isSysAdmin) {
            return response()->json(['success' => false, 'message' => 'No puede eliminar una plantilla global del sistema.'], 403);
        }

        if (!is_null($template->laboratory_id) && !\App\Models\Laboratory::allowsAllLabs($allowedLabs) && !in_array($template->laboratory_id, $allowedLabs)) {
            return response()->json(['success' => false, 'message' => 'No tiene permisos para eliminar esta plantilla.'], 403);
        }

        $template->delete();
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\ReportTemplate', 'deleted', ['id' => $id]);
        return response()->json(['success' => true]);
    }
}