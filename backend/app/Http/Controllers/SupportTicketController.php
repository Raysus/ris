<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksRisAuthorization;
use App\Models\Laboratory;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    use ChecksRisAuthorization;

    private const STAFF_ROLES = ['admin', 'sis_admin'];

    private function isStaff(Request $request): bool
    {
        $roles = $this->userEffectiveRoles($request);

        return (bool) array_intersect($roles, self::STAFF_ROLES);
    }

    private function displayName($user): ?string
    {
        if (!$user) {
            return null;
        }

        $user->loadMissing('persona');
        $p = $user->persona;
        if ($p) {
            return trim(implode(' ', array_filter([
                $p->names ?? null,
                $p->last_name_1 ?? null,
                $p->last_name_2 ?? null,
            ]))) ?: ($user->username ?? null);
        }

        return $user->username ?? null;
    }

    private function serializeTicket(SupportTicket $ticket): array
    {
        $ticket->loadMissing(['creator.persona', 'assignee.persona']);

        return [
            'id' => $ticket->id,
            'laboratory_id' => $ticket->laboratory_id,
            'subject' => $ticket->subject,
            'module' => $ticket->module,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'body' => $ticket->body,
            'admin_reply' => $ticket->admin_reply,
            'created_by' => $ticket->created_by,
            'assigned_to' => $ticket->assigned_to,
            'creator_name' => $this->displayName($ticket->creator),
            'assigned_name' => $this->displayName($ticket->assignee),
            'created_at' => optional($ticket->created_at)?->toIso8601String(),
            'updated_at' => optional($ticket->updated_at)?->toIso8601String(),
        ];
    }

    private function scopedQuery(Request $request)
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = SupportTicket::query()->withoutGlobalScopes();

        if (!Laboratory::allowsAllLabs($allowedLabs)) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('laboratory_id', $allowedLabs);
            }
        }

        $currentLabId = config('app.current_lab_id');
        if ($currentLabId && !$request->user()?->hasFullLabAccess()) {
            $currentLab = Laboratory::find($currentLabId);
            if ($currentLab && is_null($currentLab->parent_id)) {
                $childIds = Laboratory::where('parent_id', $currentLabId)->pluck('id')->all();
                $query->whereIn('laboratory_id', array_merge([$currentLabId], $childIds));
            } else {
                $query->where('laboratory_id', $currentLabId);
            }
        } elseif ($currentLabId && $request->boolean('queue') && $this->isStaff($request)) {
            // sis_admin con lab concreto: filtrar a esa sede en cola; sin lab, BelongsToLaboratory ya no aplica
            $query->where('laboratory_id', $currentLabId);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = $this->scopedQuery($request)
            ->with(['creator.persona', 'assignee.persona'])
            ->orderByDesc('created_at');

        if ($request->boolean('queue')) {
            $this->assertAnyRole($request, self::STAFF_ROLES);
        } else {
            $query->where('created_by', $user->id);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $tickets = $query->limit(200)->get()->map(fn (SupportTicket $t) => $this->serializeTicket($t));

        return response()->json(['success' => true, 'data' => $tickets]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'module' => 'required|string|max:64',
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'body' => 'required|string|max:5000',
        ]);

        $labId = config('app.current_lab_id') ?: $request->header('X-Lab-Id');
        if (!$labId) {
            return response()->json(['success' => false, 'message' => 'Seleccione un laboratorio.'], 422);
        }

        $ticket = SupportTicket::create([
            'laboratory_id' => $labId,
            'created_by' => $request->user()->id,
            'subject' => $data['subject'],
            'module' => $data['module'],
            'priority' => $data['priority'] ?? 'normal',
            'status' => 'abierto',
            'body' => $data['body'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeTicket($ticket->fresh(['creator.persona', 'assignee.persona'])),
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        $ticket = $this->scopedQuery($request)
            ->with(['creator.persona', 'assignee.persona'])
            ->findOrFail($id);

        $isOwner = $ticket->created_by === $request->user()->id;
        if (!$isOwner && !$this->isStaff($request)) {
            abort(403, 'No tiene acceso a este ticket.');
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeTicket($ticket),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $this->assertAnyRole($request, self::STAFF_ROLES);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(SupportTicket::STATUSES)],
            'admin_reply' => 'nullable|string|max:5000',
            'assigned_to' => 'nullable|uuid',
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
        ]);

        $ticket = $this->scopedQuery($request)->findOrFail($id);

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $ticket->status = $data['status'];
        }
        if (array_key_exists('admin_reply', $data)) {
            $ticket->admin_reply = $data['admin_reply'];
        }
        if (array_key_exists('priority', $data) && $data['priority'] !== null) {
            $ticket->priority = $data['priority'];
        }
        if (array_key_exists('assigned_to', $data)) {
            $ticket->assigned_to = $data['assigned_to'];
        } elseif ($ticket->assigned_to === null) {
            $ticket->assigned_to = $request->user()->id;
        }

        $ticket->save();

        return response()->json([
            'success' => true,
            'data' => $this->serializeTicket($ticket->fresh(['creator.persona', 'assignee.persona'])),
        ]);
    }
}
