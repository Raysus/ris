{{-- Copiar a: resources/views/partials/ris-reports-section.blade.php --}}
<section class="mt-8">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-bold text-text-main">Mis informes (RIS)</h2>
        <span class="text-sm text-text-muted">{{ count($risReports ?? []) }} disponible(s)</span>
    </div>

    @if (!empty($risReportsError))
        <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm">
            {{ $risReportsError }}
        </div>
    @elseif (empty($risReports))
        <div class="rounded-xl border border-line bg-white px-4 py-6 text-sm text-text-muted">
            Aún no hay informes firmados disponibles para su cuenta.
        </div>
    @else
        <div class="space-y-4">
            @foreach ($risReports as $report)
                <article class="rounded-2xl border border-line bg-white shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-line flex flex-wrap gap-3 justify-between items-start">
                        <div>
                            <p class="font-semibold text-text-main">
                                {{ collect($report['studies'] ?? [])->pluck('exam')->filter()->join(' · ') ?: 'Estudio' }}
                            </p>
                            <p class="text-sm text-text-muted mt-1">
                                {{ $report['laboratory']['name'] ?? 'Centro' }}
                                · {{ $report['study_date'] ?? '' }}
                                · {{ $report['accession_number'] ?? '' }}
                            </p>
                        </div>
                        <span class="text-xs font-semibold uppercase tracking-wide px-3 py-1 rounded-full bg-primary-light text-primary">
                            {{ $report['status'] ?? 'informe' }}
                        </span>
                    </div>

                    <div class="px-5 py-4 space-y-4">
                        @foreach ($report['studies'] ?? [] as $study)
                            @if (!empty($study['report_text']))
                                <div>
                                    <p class="text-sm font-semibold text-text-main mb-2">{{ $study['exam'] ?? 'Examen' }}</p>
                                    <div class="rounded-xl bg-bg-app border border-line px-4 py-3 text-sm whitespace-pre-wrap text-text-main leading-relaxed">
                                        {{ $study['report_text'] }}
                                    </div>
                                </div>
                            @endif
                        @endforeach

                        <div class="text-xs text-text-muted flex flex-wrap gap-x-4 gap-y-1">
                            @if (!empty($report['doctor_name']))
                                <span>Firmado por: {{ $report['doctor_name'] }}</span>
                            @endif
                            @if (!empty($report['signed_at']))
                                <span>Fecha: {{ $report['signed_at'] }}</span>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
