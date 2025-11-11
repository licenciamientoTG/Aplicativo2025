# 📋 Módulo de Facturas - Tabla Documentos

## 🎯 Descripción
Módulo completo para consultar y visualizar todas las facturas de la tabla `Documentos` con filtrado por fechas y estaciones.

---

## 📂 Archivos Creados/Modificados

### 1. **Modelo**: `_assets/models/DocumentosModel.php`

#### Método: `get_all_facturas()`
```php
/**
 * Obtiene todas las facturas de la tabla Documentos con paginación
 *
 * @param string|null $from Fecha inicio (formato: Y-m-d)
 * @param string|null $until Fecha fin (formato: Y-m-d)
 * @param int|null $codgas Código de gasolinera (opcional)
 * @param int $page Número de página
 * @param int $perPage Registros por página
 * @return array|false Array con las facturas o false si falla
 */
public function get_all_facturas(?string $from = null, ?string $until = null, ?int $codgas = null, int $page = 1, int $perPage = 100): array|false
```

**Características:**
- ✅ **Uso de parámetros preparados** (previene SQL injection)
- ✅ Filtrado por rango de fechas
- ✅ Filtrado por estación (opcional)
- ✅ Paginación incluida
- ✅ Joins optimizados con Gasolineras, Productos, Proveedores y Clientes
- ✅ Formato de factura automático (C, D, E, G, I, K, T, Z)
- ✅ Cálculos de montos con redondeo

**Datos Retornados:**
- Número de documento y formato
- Fecha y vencimiento
- Información de estación
- Producto, cantidad, precio
- Subtotal, IVA, IEPS, Total
- Tipo de documento (Compra/Venta)
- Entidad (Proveedor o Cliente)
- UUID y referencia

#### Método: `get_facturas_count()`
```php
/**
 * Obtiene el conteo total de facturas según filtros
 */
public function get_facturas_count(?string $from = null, ?string $until = null, ?int $codgas = null): int
```

---

### 2. **Controlador**: `_assets/controllers/accounting.php`

#### Método: `documentos_facturas()`
```php
/**
 * Vista para mostrar el listado de facturas de Documentos
 */
public function documentos_facturas(): void
```

**Funcionalidad:**
- Renderiza la vista HTML
- Pasa fechas por defecto (mes actual)
- Carga la lista de estaciones activas

#### Método: `documentos_facturas_table()`
```php
/**
 * Obtiene todas las facturas de la tabla Documentos para DataTables
 * Soporta filtrado por fechas y estación
 */
public function documentos_facturas_table(): void
```

**Funcionalidad:**
- Recibe parámetros POST: `from`, `until`, `codgas`
- Maneja errores con try-catch
- Formatea números con separadores de miles
- Retorna JSON para DataTables
- Log de errores server-side

---

### 3. **Vista**: `views/accounting/documentos_facturas.html`

#### Estructura HTML
```twig
{% extends "views/layouts/base.html" %}
{% block title %}Facturas - Documentos{% endblock %}
```

**Componentes:**

1. **Filtros de Búsqueda**
   - Campo de fecha inicio (primer día del mes por defecto)
   - Campo de fecha fin (fecha actual por defecto)
   - Select de estaciones (cargado dinámicamente)
   - Botón de búsqueda

2. **Tabla de Datos**
   - DataTable responsive con 15 columnas
   - Paginación: 10, 25, 50, 100, Todos
   - Ordenamiento por fecha descendente
   - Badges de colores para tipo de documento:
     - 🔵 **Compra**: Celeste
     - 🟢 **Venta**: Verde

3. **Funcionalidades JavaScript**
   - Auto-carga al iniciar
   - Sistema de alertas Bootstrap
   - Exportación a Excel
   - Loading state durante peticiones
   - Manejo de errores

#### DataTable Features
```javascript
pageLength: 50
lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Todos"]]
language: 'es-ES'
order: [[2, 'desc']] // Por fecha descendente
```

---

## 🔗 Rutas de Acceso

### Vista Principal
```
GET /accounting/documentos_facturas
```

### Endpoint AJAX
```
POST /accounting/documentos_facturas_table

Parámetros:
- from: string (Y-m-d)
- until: string (Y-m-d)
- codgas: int|null (opcional)

Response:
{
  "data": [
    {
      "NumeroDocumento": "1200123456",
      "FacturaFormateada": "D 0123456",
      "Fecha": "2025-01-15",
      "Vencimiento": "2025-02-15",
      "Estacion": "GG",
      "EstacionNombre": "Gemela Grande",
      "Producto": "T-Maxima Regular",
      "Cantidad": "5,000.000",
      "Precio": "$20.50",
      "Subtotal": "$102,500.00",
      "IVA": "$16,400.00",
      "IEPS": "$0.00",
      "Total": "$118,900.00",
      "TipoDocumento": "Compra",
      "EntidadNombre": "PEMEX Transformación Industrial",
      "UUID": "123e4567-e89b-12d3-a456-426614174000",
      "Referencia": "@F:A123@"
    }
  ]
}
```

---

## 🎨 Estilos CSS Personalizados

```css
.badge-compra {
    background-color: #0dcaf0; /* Celeste */
    color: #000;
}

.badge-venta {
    background-color: #198754; /* Verde */
}

.loading {
    opacity: 0.5;
    pointer-events: none;
    position: relative;
}

.loading::after {
    content: 'Cargando...';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 1.5rem;
    font-weight: bold;
    color: #0d6efd;
}
```

---

## 🔒 Seguridad Implementada

### ✅ Prevención de SQL Injection
```php
// ✅ CORRECTO - Uso de parámetros preparados
$query = "SELECT ... WHERE t2.fch BETWEEN ? AND ?";
$params = [$fromInt, $untilInt];
return $this->sql->select($query, $params);
```

### ✅ Validación de Entrada
```php
// Validación de método HTTP
if (!preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
    json_output(['error' => 'Método no permitido']);
    return;
}

// Validación de parámetro numérico
$codgas = isset($_POST['codgas']) && $_POST['codgas'] !== '' ? (int)$_POST['codgas'] : null;
```

### ✅ Manejo de Errores
```php
try {
    // Operaciones de base de datos
} catch (Exception $e) {
    error_log("Error en documentos_facturas_table: " . $e->getMessage());
    json_output(['error' => 'Error al obtener las facturas', 'data' => []]);
}
```

---

## 📊 Columnas de la Tabla

| # | Columna | Tipo | Descripción |
|---|---------|------|-------------|
| 1 | Número | int | Número de documento interno |
| 2 | Factura | string | Formato de factura (ej: "D 0123456") |
| 3 | Fecha | date | Fecha de emisión |
| 4 | Vencimiento | date | Fecha de vencimiento |
| 5 | Estación | string | Código abreviado + nombre completo |
| 6 | Tipo | badge | Compra o Venta (con color) |
| 7 | Entidad | string | Proveedor o Cliente |
| 8 | Producto | string | Nombre del producto |
| 9 | Cantidad | decimal | Cantidad con 3 decimales |
| 10 | Precio | currency | Precio unitario |
| 11 | Subtotal | currency | Monto sin impuestos |
| 12 | IVA | currency | Impuesto al Valor Agregado |
| 13 | IEPS | currency | Impuesto Especial sobre Productos y Servicios |
| 14 | Total | currency | Monto total (bold) |
| 15 | UUID | string | UUID fiscal (truncado con tooltip) |

---

## 🚀 Cómo Usar

### Paso 1: Acceder al módulo
```
http://tu-dominio/accounting/documentos_facturas
```

### Paso 2: Configurar filtros
1. Seleccionar fecha de inicio
2. Seleccionar fecha de fin
3. (Opcional) Seleccionar una estación específica
4. Click en "Buscar Facturas"

### Paso 3: Interactuar con los datos
- **Ordenar**: Click en los encabezados de columna
- **Buscar**: Usar el campo de búsqueda global
- **Filtrar**: Usar los filtros de pie de tabla
- **Exportar**: Click en "Exportar a Excel"
- **Paginar**: Usar controles de paginación

---

## 🎯 Ejemplos de Consultas

### Todas las facturas del mes actual
```javascript
from: '2025-01-01'
until: '2025-01-31'
codgas: '' // Todas las estaciones
```

### Facturas de una estación específica
```javascript
from: '2025-01-01'
until: '2025-01-31'
codgas: '2' // Gemela Grande
```

### Rango de fechas personalizado
```javascript
from: '2024-12-01'
until: '2025-01-15'
codgas: ''
```

---

## 📈 Mejoras Futuras Sugeridas

### Corto Plazo
- [ ] Agregar filtro por tipo de documento (Compra/Venta)
- [ ] Agregar filtro por producto
- [ ] Implementar búsqueda por UUID completo
- [ ] Agregar exportación a PDF

### Mediano Plazo
- [ ] Implementar paginación server-side para grandes volúmenes
- [ ] Agregar gráficas de resumen (totales por mes, por estación)
- [ ] Crear vista de detalle al click en cada factura
- [ ] Implementar caché para consultas frecuentes

### Largo Plazo
- [ ] Dashboard analítico con KPIs
- [ ] Integración con sistema de alertas
- [ ] API RESTful para integraciones externas
- [ ] Sistema de reportes programados

---

## 🐛 Troubleshooting

### Error: "No se cargan las facturas"
**Solución:**
1. Verificar conexión a base de datos
2. Revisar logs en el navegador (F12 → Console)
3. Verificar logs de PHP (`error_log`)
4. Confirmar que las fechas tienen el formato correcto

### Error: "Error al obtener las facturas"
**Solución:**
1. Verificar permisos del usuario en SQL Server
2. Confirmar que las tablas existen: `Documentos`, `DocumentosC`, `Gasolineras`, `Productos`, `Proveedores`, `Clientes`
3. Revisar función `dateToInt()` en helpers

### La tabla no se muestra
**Solución:**
1. Verificar que jQuery está cargado
2. Confirmar que DataTables CSS/JS están incluidos
3. Abrir consola del navegador y buscar errores JavaScript
4. Verificar que Feather Icons está cargado

---

## 📞 Soporte

Para soporte técnico o reportar bugs:
- **Archivo de logs**: Revisar logs de PHP en servidor
- **Logs JavaScript**: Console del navegador (F12)
- **Base de datos**: Verificar conectividad y permisos

---

## ✅ Checklist de Implementación

- [x] Modelo: Método `get_all_facturas()` con parámetros seguros
- [x] Modelo: Método `get_facturas_count()` para paginación
- [x] Controlador: Vista `documentos_facturas()`
- [x] Controlador: Endpoint `documentos_facturas_table()`
- [x] Vista: HTML con filtros y DataTable
- [x] Vista: JavaScript con manejo de errores
- [x] Vista: Estilos CSS personalizados
- [x] Seguridad: Prevención de SQL injection
- [x] Seguridad: Validación de entrada
- [x] Seguridad: Manejo de errores
- [x] UX: Sistema de alertas
- [x] UX: Loading states
- [x] UX: Exportación a Excel
- [x] Documentación completa

---

## 📝 Notas Adicionales

### Convenciones de Nomenclatura de Facturas

El sistema utiliza prefijos alfabéticos para identificar diferentes series de facturas:

- **C**: 1100000000 - 1199999999
- **D**: 1200000000 - 1299999999
- **E**: 1300000000 - 1399999999
- **G**: 1500000000 - 1599999999
- **I**: 1700000000 - 1799999999
- **K**: 1900000000 - 1999999999
- **T**: 2000000000 - 2099999999
- **Z**: 2100000000 - 2499999999

### Formato de Fechas en SQL Server

El proyecto usa una función personalizada `dateToInt()` que convierte fechas a días desde `1900-01-01`.

```php
// Conversión de fecha
$fromInt = dateToInt('2025-01-01'); // Resultado: 45658
```

Para mostrar:
```sql
CONVERT(VARCHAR(10), DATEADD(DAY, -1, t2.fch), 23) AS Fecha
```

---

**Versión:** 1.0.0
**Fecha:** 2025-01-11
**Autor:** Sistema de Gestión TotalGas
