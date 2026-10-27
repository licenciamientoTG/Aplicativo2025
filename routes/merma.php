<?php
// Sin permission: en el Router, igual que el resto de la migracion (D9
// pospuesto): el control sigue siendo authorized() dentro del controlador.
// sync/ y sync-diario/ van con methods: [] porque isCron() acepta el
// cron_token tanto por GET como por POST (ver Merma::isCron()); el cron
// propio (cron/merma_sync_diario.php) llama sync_diario() directo en PHP,
// sin pasar por esta ruta.
// corregir_fisico no tiene ruta nueva: sin ningun caller en JS/vistas,
// superado por corregir_fisico_lote; sigue alcanzable solo por el fallback legacy.
return [
    group('merma/', [
        // Sin alias: "analisis"/"detalle"/"ventas" no tienen guion bajo que
        // convertir, asi que la URL kebab-case es idéntica a la vieja.
        route('analisis/', [Merma::class, 'analisis'], name: 'merma.analisis', methods: ['GET']),
        route('detalle/<int:codgas>/', [Merma::class, 'detalle'], name: 'merma.detalle', methods: ['GET']),
        route('ventas/', [Merma::class, 'ventas'], name: 'merma.ventas', methods: ['GET']),
        route('ventas-analisis-generar/', [Merma::class, 'ventas_analisis_generar'], name: 'merma.ventas_analisis_generar', methods: ['POST'], alias: ['merma/ventas_analisis_generar']),
        route('ventas-rentabilidad/', [Merma::class, 'ventas_rentabilidad'], name: 'merma.ventas_rentabilidad', methods: ['GET'], alias: ['merma/ventas_rentabilidad']),
        route('ventas-historico/', [Merma::class, 'ventas_historico'], name: 'merma.ventas_historico', methods: ['GET'], alias: ['merma/ventas_historico']),
        route('ventas-excel/', [Merma::class, 'ventas_excel'], name: 'merma.ventas_excel', methods: ['GET'], alias: ['merma/ventas_excel']),
        route('cortes-fisicos/', [Merma::class, 'cortes_fisicos'], name: 'merma.cortes_fisicos', methods: ['POST'], alias: ['merma/cortes_fisicos']),
        route('cambios-fisico/', [Merma::class, 'cambios_fisico'], name: 'merma.cambios_fisico', methods: ['POST'], alias: ['merma/cambios_fisico']),
        route('compras-recepcion/', [Merma::class, 'compras_recepcion'], name: 'merma.compras_recepcion', methods: ['POST'], alias: ['merma/compras_recepcion']),
        route('excluir-compra/', [Merma::class, 'excluir_compra'], name: 'merma.excluir_compra', methods: ['POST'], alias: ['merma/excluir_compra']),
        route('incluir-compra/', [Merma::class, 'incluir_compra'], name: 'merma.incluir_compra', methods: ['POST'], alias: ['merma/incluir_compra']),
        route('corregir-fisico-lote/', [Merma::class, 'corregir_fisico_lote'], name: 'merma.corregir_fisico_lote', methods: ['POST'], alias: ['merma/corregir_fisico_lote']),
        route('evidencia-fisico/', [Merma::class, 'evidencia_fisico'], name: 'merma.evidencia_fisico', methods: ['POST'], alias: ['merma/evidencia_fisico']),
        route('subir-evidencia-fisico/', [Merma::class, 'subir_evidencia_fisico'], name: 'merma.subir_evidencia_fisico', methods: ['POST'], alias: ['merma/subir_evidencia_fisico']),
        route('eliminar-evidencia-fisico/', [Merma::class, 'eliminar_evidencia_fisico'], name: 'merma.eliminar_evidencia_fisico', methods: ['POST'], alias: ['merma/eliminar_evidencia_fisico']),
        route('view-evidencia-fisico/<int:id>/', [Merma::class, 'view_evidencia_fisico'], name: 'merma.view_evidencia_fisico', methods: ['GET'], alias: ['merma/view_evidencia_fisico/<int:id>']),
        route('sync/', [Merma::class, 'sync'], name: 'merma.sync', methods: []),
        route('sync-diario/', [Merma::class, 'sync_diario'], name: 'merma.sync_diario', methods: [], alias: ['merma/sync_diario']),
        route('preview-balance-praxedis/', [Merma::class, 'preview_balance_praxedis'], name: 'merma.preview_balance_praxedis', methods: ['POST'], alias: ['merma/preview_balance_praxedis']),
        route('guardar-balance-praxedis/', [Merma::class, 'guardar_balance_praxedis'], name: 'merma.guardar_balance_praxedis', methods: ['POST'], alias: ['merma/guardar_balance_praxedis']),
        route('preview-colosio-pdf/', [Merma::class, 'preview_colosio_pdf'], name: 'merma.preview_colosio_pdf', methods: ['POST'], alias: ['merma/preview_colosio_pdf']),
        route('guardar-colosio-pdf/', [Merma::class, 'guardar_colosio_pdf'], name: 'merma.guardar_colosio_pdf', methods: ['POST'], alias: ['merma/guardar_colosio_pdf']),
        route('guardar-manual/', [Merma::class, 'guardar_manual'], name: 'merma.guardar_manual', methods: ['POST'], alias: ['merma/guardar_manual']),
        route('guardar-precio/', [Merma::class, 'guardar_precio'], name: 'merma.guardar_precio', methods: ['POST'], alias: ['merma/guardar_precio']),
    ]),
];
