<?php

namespace App\Services;

use App\Mail\SupportTicketCreatedMail;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SupportTicketNotificationService
{
    private const STAFF_ROLES = ['admin', 'sis_admin'];

    public function notifyStaffOfNewTicket(SupportTicket $ticket): void
    {
        try {
            $ticket->loadMissing(['laboratory', 'creator.persona']);
            $recipients = $this->staffRecipientsForTicket($ticket);

            foreach ($recipients as $user) {
                $email = $user->persona?->email;
                if (!filled($email)) {
                    continue;
                }

                Mail::to($email)->queue(new SupportTicketCreatedMail($ticket));
            }

            if ($recipients->isEmpty()) {
                Log::info('Support ticket creado sin destinatarios de correo', [
                    'ticket_id' => $ticket->id,
                    'laboratory_id' => $ticket->laboratory_id,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('No se pudo notificar ticket de soporte: ' . $e->getMessage(), [
                'ticket_id' => $ticket->id,
            ]);
        }
    }

    /**
     * admin del lab + sis_admin globales (o con acceso al lab).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function staffRecipientsForTicket(SupportTicket $ticket)
    {
        $labId = $ticket->laboratory_id;

        return User::query()
            ->with(['persona', 'tipoUsuario', 'laboratories'])
            ->where('is_active', true)
            ->where(function ($q) use ($labId) {
                $q->whereHas('tipoUsuario', fn ($tq) => $tq->whereIn('name', self::STAFF_ROLES))
                    ->orWhereJsonContains('settings->roles', 'admin')
                    ->orWhereJsonContains('settings->roles', 'sis_admin');
            })
            ->get()
            ->filter(function (User $user) use ($labId) {
                if ($user->hasFullLabAccess()) {
                    return true;
                }

                $roles = [];
                if ($user->tipoUsuario?->name) {
                    $roles[] = strtolower((string) $user->tipoUsuario->name);
                }
                $settingsRoles = is_array($user->settings) ? ($user->settings['roles'] ?? []) : [];
                foreach ($settingsRoles as $role) {
                    $roles[] = strtolower((string) $role);
                }
                $roles = array_unique($roles);

                if (!array_intersect($roles, self::STAFF_ROLES)) {
                    return false;
                }

                return $user->laboratories->contains('id', $labId);
            })
            ->unique('id')
            ->values();
    }
}
