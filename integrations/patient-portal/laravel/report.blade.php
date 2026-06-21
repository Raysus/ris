@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Informe médico</h4>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            Imprimir / PDF
        </button>
    </div>

    <div class="bg-white border rounded shadow-sm p-4">
        {!! $reportHtml !!}
    </div>
</div>

<style>
@media print {
    .btn, nav, header, footer { display: none !important; }
    .container { max-width: 100% !important; }
    .bg-white { border: none !important; box-shadow: none !important; }
}
</style>
@endsection
