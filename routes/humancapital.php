<?php
// procesarEmpleado y parseFecha son funciones internas: sin ruta (D11).
return [
    group('humancapital/', [
        route('employees/', [HumanCapital::class, 'employees'], name: 'humancapital.employees', methods: ['GET']),
        route('datatables-employees/', [HumanCapital::class, 'datatables_employees'], name: 'humancapital.datatables_employees', methods: ['POST'], alias: ['humancapital/datatables_employees']),
        route('import-file-reporte-empleados/', [HumanCapital::class, 'import_file_reporte_empleados'], name: 'humancapital.import_file_reporte_empleados', methods: ['POST'], alias: ['humancapital/import_file_reporte_empleados']),
    ]),
];
