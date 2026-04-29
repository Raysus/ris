<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SyncEntityToCloud implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $modelClass;
    public $modelData;
    public $action; // 'created', 'updated', o 'deleted'

    // Intentará enviar hasta 5 veces antes de fallar permanentemente
    public $tries = 5; 
    
    // Si falla, esperará 60 segundos antes de reintentar
    public $backoff = 60; 

    public function __construct($modelClass, $modelData, $action)
    {
        $this->modelClass = $modelClass;
        $this->modelData = $modelData;
        $this->action = $action;
    }

    public function handle()
    {
        // 1. Apuntar a la API de tu Servidor en la Nube
        $cloudUrl = env('CLOUD_API_URL', 'https://api.healthticloud.cl/api/sync/receive');
        $labToken = env('LAB_SYNC_TOKEN'); // Un token secreto configurado en el .env local

        // 2. Enviar el paquete
        $response = Http::withToken($labToken)
            ->timeout(15) // No quedarse colgado si no hay internet
            ->post($cloudUrl, [
                'laboratory_id' => config('app.current_lab_id'),
                'entity_type'   => $this->modelClass, // Ej: 'App\Models\Appointment'
                'action'        => $this->action,
                'payload'       => $this->modelData
            ]);

        // 3. Manejo de fallos (Corte de internet)
        if ($response->failed()) {
            // Al lanzar la excepción, Laravel guarda el Job en la base de datos
            // y lo reintenta automáticamente después (resiliencia pura).
            throw new \Exception("Fallo al sincronizar {$this->modelClass} a la nube. HTTP: " . $response->status());
        }
    }
}