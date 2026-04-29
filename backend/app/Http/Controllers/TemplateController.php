<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ReportTemplate;

class TemplateController extends Controller
{
    private function getSecureTemplateQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = ReportTemplate::query();

        if ($allowedLabs !== ['*']) {
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
            'id' => 'nullable|integer',
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

            if (!is_null($template->laboratory_id) && $allowedLabs !== ['*'] && !in_array($template->laboratory_id, $allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado. Esta plantilla pertenece a otra sucursal.'], 403);
            }

        } else {
            $template = new ReportTemplate();

            if ($isSysAdmin && (!$labId || $labId === 'ALL')) {
                $template->laboratory_id = null;
            } else {
                if ($allowedLabs !== ['*'] && !in_array($labId, $allowedLabs)) {
                    return response()->json(['success' => false, 'message' => 'No tiene permisos para crear plantillas en esta sucursal.'], 403);
                }
                $template->laboratory_id = $labId;
            }
        }

        $template->group_code = $validated['group_code'];
        $template->title = $validated['title'];
        $template->content = $validated['content'];
        $template->save();

        return response()->json(['success' => true, 'data' => $template]);
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

        if (!is_null($template->laboratory_id) && $allowedLabs !== ['*'] && !in_array($template->laboratory_id, $allowedLabs)) {
            return response()->json(['success' => false, 'message' => 'No tiene permisos para eliminar esta plantilla.'], 403);
        }

        $template->delete();

        return response()->json(['success' => true]);
    }
}