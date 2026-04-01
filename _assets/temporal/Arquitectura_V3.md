# Arquitectura de Software — TotalGas Modulo Income / Conciliacion V3

**Fecha de actualizacion:** 31/03/2026
**Version:** 3.0
**Responsable:** Desarrollo TotalGas

---

## 1. Descripcion General del Sistema

TotalGas es una aplicacion web PHP MVC personalizada para la gestion de operaciones de estaciones de gasolina en 40+ ubicaciones. El modulo **Income** cubre el ciclo de ingresos: ventas, carga de reportes bancarios, y conciliacion de depositos contra ventas de ControlGas.

### Stack tecnologico

| Capa | Tecnologia |
|---|---|
| Servidor web (produccion) | IIS con URL Rewrite a `index.php` |
| Servidor web (desarrollo) | PHP built-in `php -S localhost:8000 router.php` |
| Lenguaje backend | PHP (sin framework, MVC manual) |
| Plantillas | Twig 3.x |
| Base de datos | SQL Server via PDO `sqlsrv` |
| Frontend | Bootstrap 4 + jQuery + FontAwesome |
| Exportacion Excel | PhpSpreadsheet 2.x |
| Correo | PHPMailer via Gmail SMTP |

---

## 2. Flujo de Solicitudes

```
Navegador
  → index.php  (lee ?url=controlador/metodo)
  → Verifica sesion activa ($_SESSION)
  → Instancia controlador desde _assets/controllers/
  → Llama al metodo del controlador
  → Controlador usa modelos de _assets/models/
  → Renderiza vista Twig desde views/
  → Responde al navegador
```

Patron de URL: `/[controlador]/[metodo]/[params-opcionales]`
Ejemplo: `/income/test_v3` → `Income::test_v3()`

---

## 3. Arquitectura General

```mermaid
flowchart TD
    Browser["Navegador del usuario"]
    idx["Punto de entrada principal - index.php"]
    auth["Verificacion de sesion activa"]
    ctrl["Controlador de Ingresos"]
    model["Modelos de datos - consultas a BD"]
    twig["Plantillas de vista - HTML"]
    db_tg["Base de datos principal TG"]
    db_sg["Base de datos regional SG12"]
    db_est["Bases de datos por estacion 40+ servidores"]

    Browser -->|"Solicita pagina"| idx
    idx --> auth
    auth -->|"Sesion valida"| ctrl
    ctrl --> model
    ctrl --> twig
    model --> db_tg
    model --> db_sg
    model -.->|"Servidores vinculados"| db_est
    twig -->|"Pagina renderizada"| Browser
```

---

## 4. Archivos Clave del Modulo Income

| Archivo | Proposito |
|---|---|
| `_assets/controllers/income.php` | Controlador principal — 8700+ lineas, ~80 metodos |
| `_assets/models/IngresosModel.php` | Modelo base de ingresos |
| `views/income/test_v3.html` | Pantalla principal de conciliacion V3 |
| `views/income/upload_reports.html` | Pantalla de carga de reportes bancarios |
| `views/income/summary_v3.html` | Dashboard de cierres y KPIs |
| `_assets/classes/header.class.php` | Constantes, credenciales BD, zona horaria |
| `_assets/classes/common/MySqlPdoHandler.class.php` | Singleton de conexion PDO |

---

## 5. Flujo de Carga de Reportes Bancarios

```mermaid
flowchart TD
    UI["Pantalla de Carga de Reportes Bancarios"]

    subgraph BancosSoportados
        SANT["Santander / Getnet .CSV"]
        BANORTE["Banorte .XLSX"]
        AMEX_R["AMEX Depositos .CSV"]
        AFIRME["Afirme .CSV"]
        AMEX_C["AMEX Comisiones e IVA .CSV/.XLSX"]
    end

    UI -->|"Usuario selecciona archivo"| SANT
    UI -->|"Usuario selecciona archivo"| BANORTE
    UI -->|"Usuario selecciona archivo"| AMEX_R
    UI -->|"Usuario selecciona archivo"| AFIRME
    UI -->|"Usuario selecciona archivo"| AMEX_C

    SANT -->|"Envio al servidor"| parse["Servidor lee, normaliza e inserta filas"]
    BANORTE -->|"Envio al servidor"| parse
    AMEX_R -->|"Envio al servidor"| parse
    AFIRME -->|"Envio al servidor"| parse
    AMEX_C -->|"Envio al servidor"| parseAmex["Servidor normaliza comisiones e IVA por deposito"]

    parse -->|"Registros unicos sin duplicados"| TV3["Depositos bancarios unificados"]
    parseAmex --> AMEXCOM["Tabla de comisiones AMEX"]

    TV3 -.->|"Usada en"| concil["Modulo de Conciliacion V3"]
    AMEXCOM -.->|"Agrega monto bruto e IVA a cada deposito AMEX"| concil
```

### Nota tecnica de carga
Los archivos se leen en el navegador con `FileReader` y se envian como **Base64** al servidor para evitar el limite de `upload_max_filesize` de PHP. El servidor decodifica, parsea y normaliza antes de insertar. Un hash unico por registro (fecha + monto + referencia) evita duplicados aunque el archivo se cargue mas de una vez.

---

## 6. Flujo Principal de Conciliacion V3 (CG vs Tesoreria)

> **Cambio importante respecto a versiones anteriores:** La conciliacion se realiza entre las **ventas de ControlGas** y los **depositos de Tesoreria**. Las transacciones bancarias individuales aparecen como panel informativo de referencia, no como objeto principal de conciliacion.

```mermaid
flowchart TD
    start(["Usuario abre la pantalla de Conciliacion"])

    subgraph Contexto
        rs["Selecciona Razon Social DIAZ GAS / FORANEAS / GASOMEX"]
        est["Selecciona Estacion"]
        banco["Selecciona Banco"]
        afil["Selecciona Afiliacion terminal bancaria"]
        mes["Selecciona Mes y Año"]
    end

    subgraph CargaDatos
        cg_api["Carga ventas del dia desde ControlGas"]
        tes_api["Carga depositos bancarios del mes"]
        conc_api["Carga conciliaciones ya realizadas"]
        trans_api["Carga transitos activos del mes anterior"]
        dif_api["Carga diferidos activos del mes"]
    end

    subgraph Pantalla
        panel_cg["Panel izquierdo - Ventas ControlGas por dia"]
        panel_tes["Panel derecho - Depositos bancarios"]
        panel_tx["Panel informativo - Transacciones del banco"]
    end

    subgraph Acciones
        action_ok["Conciliar venta con deposito"]
        action_trans["Enviar a Transito - el deposito cae en el mes siguiente"]
        action_dif["Diferir - el deposito cae en otro dia del mismo mes"]
        action_undo["Deshacer conciliacion individual"]
    end

    subgraph CierreMes
        preview["Ver resumen antes de cerrar"]
        cerrar["Cerrar el mes"]
        reabrir["Reabrir mes cerrado"]
    end

    start --> rs --> est --> banco --> afil --> mes
    mes --> cg_api & tes_api & conc_api & trans_api & dif_api
    cg_api --> panel_cg
    tes_api --> panel_tes
    conc_api --> panel_cg & panel_tes
    trans_api --> panel_cg
    dif_api --> panel_cg

    panel_cg & panel_tes --> action_ok
    panel_cg --> action_trans & action_dif & action_undo

    action_ok -->|"Guarda grupo de conciliacion"| grp["Registro de conciliacion en BD"]
    action_trans -->|"Crea registro de transito"| tv3t["Transito en estado PENDIENTE"]
    action_dif -->|"Crea registro diferido"| cv3d["Diferido en estado PENDIENTE"]
    action_undo -->|"Elimina grupo de conciliacion"| grp

    grp --> preview --> cerrar --> reabrir
```

---

## 7. Mecanismo de Transitos (deposito cruza de mes)

Un **transito** ocurre cuando una venta de un mes se deposita en el mes siguiente. El corte queda partido: una parte se concilia en el mes origen y la otra genera una fila pendiente en el mes destino.

```mermaid
sequenceDiagram
    participant U as Usuario
    participant UI as Pantalla Conciliacion
    participant API as Servidor
    participant DB as Tabla Transitos

    Note over U,DB: Mes Origen ej. Enero
    U->>UI: Identifica que un corte del dia 31 se depositara en Febrero
    UI->>API: Envia solicitud de transito con montos y meses involucrados
    API->>DB: Registra transito en estado PENDIENTE y calcula monto efectivo
    DB-->>API: Confirmacion con ID del transito
    API-->>UI: Monto efectivo disponible para conciliar en enero
    UI->>UI: Muestra fila partida en Enero - monto efectivo + etiqueta TRANSITO

    Note over U,DB: Mes Destino ej. Febrero
    UI->>API: Solicita transitos pendientes que caen en Febrero
    API-->>UI: Lista de transitos pendientes
    UI->>UI: Muestra fila de cobro pendiente en el panel de Febrero
    U->>UI: Concilia la fila de transito contra el deposito de Tesoreria de Febrero
    UI->>API: Guarda la conciliacion indicando origen TRANSITO
    API->>DB: Actualiza estado a CONCILIADO
    DB-->>API: OK
    API-->>UI: Conciliacion guardada correctamente
```

---

## 8. Mecanismo de Diferidos (deposito dentro del mismo mes, dia distinto)

Un **diferido** ocurre cuando una venta de un dia se deposita en otro dia del **mismo mes**. A diferencia del transito, no cruza meses pero si cruza dias dentro del periodo activo.

```mermaid
sequenceDiagram
    participant U as Usuario
    participant UI as Pantalla Conciliacion
    participant API as Servidor
    participant DB as Tabla Diferidos

    Note over U,DB: Dentro del mismo mes
    U->>UI: Identifica que el corte del dia 5 fue depositado el dia 7 del mismo mes
    UI->>API: Envia solicitud de diferido con fecha origen dia 5 y fecha destino dia 7
    API->>DB: Registra diferido en estado PENDIENTE y valida que ambas fechas sean del mismo mes
    DB-->>API: Confirmacion con ID del diferido
    API-->>UI: Monto efectivo restante para el dia 5

    UI->>UI: Dia 5 muestra fila partida - monto efectivo + etiqueta DIFERIDO
    UI->>UI: Dia 7 muestra fila adicional disponible para conciliar

    U->>UI: Concilia la fila diferida del dia 7 contra el deposito de Tesoreria del dia 7
    UI->>API: Guarda la conciliacion indicando origen DIFERIDO
    API->>DB: Actualiza estado a CONCILIADO
    DB-->>API: OK
    API-->>UI: Conciliacion guardada correctamente
```

---

## 9. Modelo de Base de Datos — Conciliacion V3

```mermaid
erDiagram
    DepositosBancariosUnificados {
        int id_origen
        string banco_origen
        int entidad_id
        date fecha
        string referencia
        string descripcion
        decimal monto_deposito
        decimal monto_retiro
    }
    GruposDeConciliacion {
        int id
        int estacion_id
        int entidad_id
        string afiliacion
        date fecha_operativa
        date fecha_deposito
        decimal total_ventas_cg
        decimal total_depositado
        decimal diferencia
    }
    DetallesDeConciliacion {
        int id
        int grupo_id
        string origen
        string referencia_externa
        date fecha_operacion
        decimal monto
        string concepto
    }
    CierreDeMes {
        int id
        int estacion_id
        int entidad_id
        string afiliacion
        string mes
        string estado
        decimal total_ventas_cg
        decimal total_depositado
        decimal total_en_transito
        decimal total_diferencias
        date fecha_cierre
    }
    Transitos {
        int id
        int estacion_id
        int entidad_id
        string afiliacion
        string referencia_corte
        date fecha_corte
        string mes_origen
        string mes_destino
        decimal monto_corte
        decimal monto_en_transito
        decimal monto_efectivo
        string estado
    }
    Diferidos {
        int id
        int estacion_id
        int entidad_id
        string afiliacion
        string referencia_corte
        date fecha_origen
        date fecha_destino
        string mes
        decimal monto_corte
        decimal monto_diferido
        string estado
    }

    GruposDeConciliacion ||--o{ DetallesDeConciliacion : "contiene"
    CierreDeMes ||--o{ GruposDeConciliacion : "agrupa al cerrar"
    Transitos }o--|| GruposDeConciliacion : "se concilia como transito"
    Diferidos }o--|| GruposDeConciliacion : "se concilia como diferido"
    DepositosBancariosUnificados }o--|| DetallesDeConciliacion : "referenciado"
```

---

## 10. Mapa de Funciones del Modulo

```mermaid
mindmap
  root((Modulo de Ingresos y Conciliacion))
    Vistas principales
      Pantalla de conciliacion V3
      Resumen y dashboard de cierres
    Catalogos
      Obtener estaciones disponibles
      Obtener configuracion de conciliacion
    Consulta de datos
      Obtener ventas de ControlGas por dia
      Obtener depositos bancarios del mes
      Obtener transacciones del banco por afiliacion
      Obtener conciliaciones ya realizadas
      Obtener transitos pendientes del mes anterior
      Obtener transitos activos del mes
      Obtener diferidos activos del mes
    Conciliacion
      Guardar conciliacion de venta con deposito
      Deshacer conciliacion individual
      Deshacer todas las conciliaciones del mes
      Actualizar detalle de una conciliacion
      Sincronizar montos modificados en tesoreria
      Detectar cambios en depositos ya conciliados
    Transitos
      Calcular monto de transito
      Crear transito de un corte al mes siguiente
      Cancelar transito
      Crear transitos por dia completo en lote
    Diferidos
      Diferir un corte a otro dia del mismo mes
      Diferir todos los cortes de un dia en lote
      Mover diferido a otra fecha destino
      Cancelar diferido
    Cierre de mes
      Ver resumen previo al cierre
      Cerrar el mes
      Reabrir mes cerrado
      Consultar historial de cierres
      Obtener resumen general del mes
    Dashboard
      KPIs totales y tendencia por mes
      Resumen de transitos en dashboard
    Exportacion
      Exportar diferencias a Excel
      Exportar resumen general a Excel
    Carga bancaria
      Cargar reporte bancario SANT BANORTE AMEX AFIRME
      Cargar reporte de comisiones AMEX
      Consultar estado de comisiones del mes
```

---

## 11. Comparativa de Cambios Respecto a Version Anterior

| Concepto | Version anterior | Version actual |
|---|---|---|
| Objeto de conciliacion | CG vs Transacciones bancarias | CG vs Tesoreria (depositos); TX es panel informativo |
| Diferidos | No existia | CV3_Diferido — mueve parte de un corte a otro dia del mismo mes |
| Transitos | Basico | CV3_Transito — corte cuyo deposito cae en el mes siguiente |
| Razon Social | No segmentado | DIAZ GAS / FORANEAS / GASOMEX como filtro principal |
| Cierre de mes | No existia | cerrar_mes_v3 / reabrir_mes_v3 + banner de mes cerrado |
| AMEX Comisiones | No existia | Reporte separado de comisiones e IVA que enriquece el monto bruto |
| Deteccion de cambios | No existia | Detecta y sincroniza depositos modificados despues de conciliar |
| Fase 2 Valeras | No documentada | FRU redactado — TicketCard, Inburgas, Efecticard, Ultragas, Pluxee, MIFIEL, BBVA |

---

## 12. Roadmap — Fase 2: Conciliacion de Valeras

```mermaid
flowchart LR
    subgraph ProveedoresValera
        TC["TicketCard - Concilia transaccion por transaccion filtrando solo gasolinas"]
        IB["Inburgas - Concilia transaccion por transaccion con fecha de pago irregular"]
        EC["Efecticard - Concilia por factura mensual validando reembolso mas comision mas IVA igual venta"]
        UG["Ultragas - Concilia por sumatoria con dos variantes AGS y MobileFleet"]
        PL["Pluxee y Sodexo - Concilia transaccion por transaccion via portal En Punto"]
        MF["MIFIEL - Concilia ControlGas contra Tesoreria sin archivo externo"]
        BB["BBVA - Concilia depositos contra ventas CG por razon social"]
    end

    subgraph RazonesSociales
        COL["COLOSIO - Banco Santander"]
        PRAX["PRAXEDIS - Santander via portal Nexus"]
        GASO["Gasomex Ejercito Fuentes Santiago - AMEX tres cuentas compartidas"]
        GMIST["Gabriela Mistral"]
        GEN["General - BBVA"]
    end

    TC --> COL & PRAX
    EC --> COL & PRAX
    UG --> PRAX
    PL --> GASO
    MF --> GMIST
    BB --> GEN
    IB -.->|"asignacion pendiente"| COL

    subgraph MotorReutilizado
        tv3["Logica de Transitos ya implementada"]
        dv3["Logica de Diferidos ya implementada"]
        close["Cierre de mes ya implementado"]
    end

    COL & PRAX & GASO & GMIST & GEN --> tv3 & dv3 & close
```
