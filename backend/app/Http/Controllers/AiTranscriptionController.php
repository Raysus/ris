<?php

namespace App\Http\Controllers;

use App\Services\AiTranscriptionService;
use App\Support\CloudSyncMode;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Endpoint nube: recibe audio desde laboratorios locales (middleware cloud.sync).
 */
class AiTranscriptionController extends Controller
{
    public function transcribe(Request $request, AiTranscriptionService $ai)
    {
        if (!CloudSyncMode::acceptsInbound() && !CloudSyncMode::isCloud()) {
            return response()->json([
                'success' => false,
                'message' => 'Este servidor no acepta transcripción IA inbound.',
            ], 403);
        }

        $request->validate([
            'audio' => 'required|file|mimes:webm,mp3,wav,ogg,mp4,m4a,mpeg|max:20480',
            'language' => 'nullable|string|max:8',
        ]);

        try {
            $text = $ai->transcribeOnCloud(
                $request->file('audio'),
                $request->input('language')
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Error interno al transcribir.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'text' => $text,
        ]);
    }
}
