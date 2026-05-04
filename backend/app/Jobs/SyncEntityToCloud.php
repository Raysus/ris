<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncEntityToCloud implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $entityType;
    public $action;
    public $payload;

    public $tries = 5;
    public $backoff = 30;

    public function __construct($entityType, $action, $payload)
    {
        $this->entityType = $entityType;
        $this->action = $action;
        $this->payload = $payload;
    }

    public function handle()
    {
        $cloudUrl = env('CLOUD_SERVER_URL');
        $secret = env('CLOUD_SYNC_SECRET');

        if (!$cloudUrl || !$secret) {
            Log::error("Faltan variables de entorno para sincronizar en la nube.");
            return;
        }

        // === 📦 EMPAQUETAR ARCHIVOS FÍSICOS A BASE64 ===
        $this->packFiles();

        // 🔥 Disfrazamos la petición para evadir Cloudflare
        $response = Http::withoutVerifying()
            ->withToken($secret)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'X-Requested-With' => 'XMLHttpRequest'
            ])
            ->timeout(15)
            ->post($cloudUrl, [
                'entity_type' => $this->entityType,
                'action' => $this->action,
                'payload' => $this->payload
            ]);

        if ($response->failed()) {
            throw new \Exception("Fallo al sincronizar {$this->entityType}. Nube respondió: " . $response->body());
        }
    }

    /**
     * Busca rutas de archivos en el payload y adjunta su contenido en Base64
     */
    private function packFiles()
    {
        $columns = ['medical_order_path', 'survey_path', 'signature_path', 'audio_path'];

        // Revisar datos principales (Citas, Usuarios)
        foreach ($columns as $col) {
            if (!empty($this->payload[$col])) {
                $base64 = $this->fileToBase64($this->payload[$col]);
                if ($base64)
                    $this->payload[$col . '_base64'] = $base64;
            }
        }

        // Revisar datos anidados (Estudios dentro de una Cita)
        if (isset($this->payload['studies']) && is_array($this->payload['studies'])) {
            foreach ($this->payload['studies'] as $key => $study) {
                if (!empty($study['audio_path'])) {
                    $base64 = $this->fileToBase64($study['audio_path']);
                    if ($base64)
                        $this->payload['studies'][$key]['audio_path_base64'] = $base64;
                }
            }
        }
    }

    private function fileToBase64($path)
    {
        $cleanPath = str_replace('/storage/', '', $path); // Normalizamos la ruta local
        if (Storage::disk('public')->exists($cleanPath)) {
            $content = Storage::disk('public')->get($cleanPath);
            $mime = Storage::disk('public')->mimeType($cleanPath);
            return 'data:' . $mime . ';base64,' . base64_encode($content);
        }
        return null;
    }
}