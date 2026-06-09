<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Support\ModalityCode;
use App\Support\PacsMwlProbe;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    private function getSecureMachineQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Machine::query();

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('laboratory_id', $allowedLabs);
            }
        }
        return $query;
    }

    public function index(Request $request)
    {
        $machines = $this->getSecureMachineQuery()
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $machines]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|string', // 🔥 CORRECCIÓN: Era integer, ahora es string (UUID)
            'name' => 'required|string|max:255',
            'group' => 'required|string',
            'manufacturer' => 'nullable|string',
            'model_name' => 'nullable|string',
            'description' => 'nullable|string',
            // === NUEVOS CAMPOS DICOM ===
            'ae_title' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:45',
            'port' => 'nullable|integer'
        ]);

        $allowedLabs = config('app.allowed_lab_ids');
        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');
        $group = ModalityCode::normalizeGroup($validated['group']);

        $color = match ($group) {
            'CR' => '#43A047',
            'DX' => '#2E7D32',
            'RX' => '#66BB6A',
            'CT', 'CBCT' => '#FF9800',
            'MRI' => '#E91E63',
            'US' => '#9C27B0',
            'MAMO' => '#FFC0CB',
            'DEXA' => '#795548',
            'IO' => '#5C6BC0',
            'NM', 'PT' => '#37474F',
            'RF', 'XA' => '#607D8B',
            'OT' => '#78909C',
            default => '#3788d8',
        };

        if (!empty($validated['id'])) {
            $machine = $this->getSecureMachineQuery()->findOrFail($validated['id']);
            $machine->update([
                'name' => $validated['name'],
                'group' => $group,
                'manufacturer' => $validated['manufacturer'] ?? null,
                'model_name' => $validated['model_name'] ?? null,
                'description' => $validated['description'] ?? null,
                'event_color' => $color,
                'ae_title' => $validated['ae_title'] ?? null,
                'ip_address' => $validated['ip_address'] ?? null,
                'port' => $validated['port'] ?? null,
            ]);
        } else {
            if (!$labId)
                return response()->json(['success' => false, 'message' => 'Debe seleccionar un laboratorio.'], 400);
            if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs) && !in_array($labId, $allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
            }

            $machine = Machine::create([
                'laboratory_id' => $labId,
                'name' => $validated['name'],
                'group' => $group,
                'manufacturer' => $validated['manufacturer'] ?? null,
                'model_name' => $validated['model_name'] ?? null,
                'description' => $validated['description'] ?? null,
                'event_color' => $color,
                'is_active' => true,
                'ae_title' => $validated['ae_title'] ?? null,
                'ip_address' => $validated['ip_address'] ?? null,
                'port' => $validated['port'] ?? null,
            ]);
        }

        return response()->json(['success' => true, 'machine' => $machine]);
    }

    public function destroy($id)
    {
        $machine = $this->getSecureMachineQuery()->find($id);

        if ($machine) {
            $machine->delete();
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Sala no encontrada o acceso denegado'], 404);
    }

    /**
     * Diagnóstico de red para salas DICOM.
     * 1) TCP al equipo en LAN (opcional; Fuji FCR suele no aceptar TCP entrante).
     * 2) TCP al PACS MWL (C-FIND) — lo que el FCR debe alcanzar en :4242.
     */
    public function pingDicom($id)
    {
        $machine = $this->getSecureMachineQuery()->findOrFail($id);

        $host = trim((string) ($machine->ip_address ?? ''));
        $port = (int) ($machine->port ?? 0);
        $stationAe = $machine->ae_title ?: ('SALA_' . $machine->id);
        $modality = ModalityCode::forDicomWorklist($machine->group ?? 'US', $stationAe);
        $isCrFamily = in_array(
            ModalityCode::normalizeGroup($machine->group),
            ['CR', 'DX', 'MAMO', 'RX'],
            true
        );

        $equipmentTcp = ['ok' => false, 'host' => $host, 'port' => $port, 'detail' => ''];
        if ($host !== '' && $port >= 1 && $port <= 65535) {
            $errCode = 0;
            $errStr = '';
            $fp = @fsockopen($host, $port, $errCode, $errStr, 3);
            if ($fp) {
                fclose($fp);
                $equipmentTcp['ok'] = true;
                $equipmentTcp['detail'] = "TCP abierto en {$host}:{$port}.";
            } else {
                $equipmentTcp['detail'] = $isCrFamily
                    ? "Sin TCP entrante en {$host}:{$port} ({$errStr}). En Fuji FCR es habitual: el equipo solo consulta MWL al PACS, no recibe conexiones del RIS."
                    : "Sin TCP en {$host}:{$port} ({$errStr}).";
            }
        } else {
            $equipmentTcp['detail'] = 'IP/puerto de la sala no configurados (no afecta MWL si el FCR alcanza el PACS).';
        }

        $pacs = \App\Support\OrthancUrl::worklistDicomTarget();
        $pacsErr = '';
        $pacsFp = @fsockopen($pacs['host'], $pacs['port'], $pacsCode, $pacsErr, 3);
        $pacsOk = (bool) $pacsFp;
        if ($pacsFp) {
            fclose($pacsFp);
        }

        $httpHost = parse_url(\App\Support\OrthancUrl::worklistBase(), PHP_URL_HOST) ?: '';
        $pacsDetail = $pacsOk
            ? "PACS MWL alcanzable en {$pacs['host']}:{$pacs['port']} (AE destino «{$pacs['aet']}»)."
            : "PACS MWL NO alcanzable en {$pacs['host']}:{$pacs['port']} ({$pacsErr}). "
                . ($httpHost !== '' && $httpHost !== $pacs['host']
                    ? "No use «{$httpHost}:4242» en el FCR; use la IP DICOM «{$pacs['host']}»."
                    : 'Revise firewall y PACS_DICOM_HOST en .env.');

        $cfind = $pacsOk
            ? PacsMwlProbe::run($stationAe, $modality)
            : ['ok' => false, 'pending' => false, 'failed' => true, 'output' => '', 'calling_ae' => $stationAe, 'station_ae' => $stationAe, 'modality' => $modality, 'date' => date('Ymd')];

        $cfindDetail = '';
        if ($pacsOk) {
            if ($cfind['ok']) {
                $cfindDetail = "C-FIND OK con AE «{$stationAe}» (hay worklist para hoy «{$cfind['date']}»).";
            } elseif ($cfind['failed']) {
                $cfindDetail = "C-FIND RECHAZADO (Find Failed) con AE «{$stationAe}». El PACS exige que el Local AE del FCR Console sea exactamente «{$stationAe}».";
            } else {
                $cfindDetail = "C-FIND sin resultados para hoy «{$cfind['date']}» con estación «{$stationAe}» y modalidad «{$modality}». Reenvíe la worklist o ajuste la fecha en el FCR.";
            }
        }

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago')))->format('d.m.Y');
        $fcrHint = "FCR Console: remoto «{$pacs['host']}»:{$pacs['port']}, AE destino (called) «{$pacs['aet']}», "
            . "AE local (calling) OBLIGATORIO «{$stationAe}» (si el FCR tiene otro AE, el PACS rechaza la consulta). "
            . "Modalidad «{$modality}», fecha hoy {$today} (día.mes.año en pantalla Fuji) o la de la cita. "
            . 'Alternativa: búsqueda por Patient ID + Accession en el FCR.';

        $message = trim($equipmentTcp['detail'] . ' ' . $pacsDetail . ' ' . $cfindDetail . ' ' . $fcrHint);

        return response()->json([
            'success' => $pacsOk && ($cfind['ok'] || !$cfind['failed']),
            'equipment_tcp' => $equipmentTcp,
            'pacs_mwl' => [
                'ok' => $pacsOk,
                'host' => $pacs['host'],
                'port' => $pacs['port'],
                'aet' => $pacs['aet'],
                'detail' => $pacsDetail,
                'cfind_ok' => $cfind['ok'],
                'cfind_failed' => $cfind['failed'],
                'cfind_pending' => $cfind['pending'],
                'cfind_date' => $cfind['date'],
                'cfind_detail' => $cfindDetail,
            ],
            'station_ae' => $stationAe,
            'modality' => $modality,
            'message' => $message,
        ], $pacsOk ? 200 : 408);
    }
}