<?php

namespace App\Jobs;

use App\Models\LaboratoryUser;
use App\Models\User;
use App\Support\CloudSyncMode;
use App\Support\LaboratorySyncRelay;
use App\Support\RisHttp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Nube → lab: persona → usuario → pivotes laboratory_user (secretaria/transcriptora remotas).
 */
class RelayUserBundleToLocalLab implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $userId;

    public string $action;

    public int $tries = 3;

    public int $backoff = 20;

    public int $uniqueFor = 90;

    public function __construct(string $userId, string $action = 'updated')
    {
        $this->userId = $userId;
        $this->action = $action;
    }

    public function uniqueId(): string
    {
        return 'user-bundle-relay:' . $this->action . ':' . $this->userId;
    }

    public function handle(): void
    {
        if (!CloudSyncMode::isCloud()) {
            return;
        }

        $secret = config('cloud_sync.secret');
        if (!filled($secret)) {
            return;
        }

        if ($this->action === 'deleted') {
            $user = User::with('laboratories')->withTrashed()->find($this->userId);
            $labs = $user?->laboratories ?? collect();
            foreach ($labs as $lab) {
                $this->postChunks($lab, [
                    ['model' => 'App\\Models\\User', 'action' => 'deleted', 'data' => ['id' => $this->userId]],
                ], $secret);
            }

            return;
        }

        $user = User::with(['persona', 'tipoUsuario', 'laboratories'])->find($this->userId);
        if (!$user?->persona || $user->laboratories->isEmpty()) {
            return;
        }

        $persona = $user->persona->toArray();
        $userData = $user->makeVisible(['password'])->toArray();
        $userData['persona'] = $persona;
        if ($user->tipoUsuario) {
            $userData['tipo_usuario'] = $user->tipoUsuario->toArray();
        }

        $pivots = LaboratoryUser::query()
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (LaboratoryUser $pivot) => [
                'model' => 'App\\Models\\LaboratoryUser',
                'action' => 'updated',
                'data' => $pivot->toArray(),
            ])
            ->all();

        $chunks = array_merge([
            ['model' => 'App\\Models\\Persona', 'action' => 'updated', 'data' => $persona],
            ['model' => 'App\\Models\\User', 'action' => $this->action, 'data' => $userData],
        ], $pivots);

        foreach ($user->laboratories as $lab) {
            $this->postChunks($lab, $chunks, $secret);
        }
    }

    /**
     * @param  list<array{model: string, action: string, data: array}>  $chunks
     */
    private function postChunks($lab, array $chunks, string $secret): void
    {
        $relayUrl = LaboratorySyncRelay::resolveEntityUrl($lab);
        if (!$relayUrl) {
            return;
        }

        $response = RisHttp::client(30)
            ->withToken($secret)
            ->acceptJson()
            ->asJson()
            ->post($relayUrl, ['chunks' => $chunks]);

        if (!$response->successful()) {
            Log::warning('RelayUserBundleToLocalLab falló', [
                'user_id' => $this->userId,
                'lab' => $lab->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $response->throw();
        }
    }
}
