-- =============================================================
-- Alta de cuenta bancaria (solicitud de alta de cuentas de proveedores)
-- Cuelga del menu Pagos extraordinarios. Cualquier usuario solicita; Tesoreria
-- (permiso 109) aprueba y la cuenta pasa a TG.dbo.CatalogosCuentasBancarias.
-- Campos tomados de la hoja SPEI de "SOLICITUD ALTA DE CUENTA BANCARIA.xlsx";
-- el catalogo de bancos sale de su hoja CLAVES.
-- Script idempotente: se puede correr mas de una vez.
-- Estatus (bank_account_requests.status): 1 pendiente, 2 aprobada, 3 rechazada
-- =============================================================
USE [TG]
GO

-- Catalogo de bancos (nombre como se guarda en CatalogosCuentasBancarias.Banco)
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'bank_account_banks')
BEGIN
    CREATE TABLE [TG].[dbo].[bank_account_banks] (
        id             INT IDENTITY(1,1) PRIMARY KEY,
        nombre         VARCHAR(100) NOT NULL,
        no_inst        INT          NOT NULL,
        clave_transfer VARCHAR(20)  NOT NULL,
        activo         BIT          NOT NULL DEFAULT 1
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_bank_account_banks_nombre')
    CREATE UNIQUE INDEX UX_bank_account_banks_nombre ON [TG].[dbo].[bank_account_banks] (nombre);
GO

INSERT INTO [TG].[dbo].[bank_account_banks] (nombre, no_inst, clave_transfer)
SELECT v.nombre, v.no_inst, v.clave_transfer
FROM (VALUES
    (N'BANCOMEXT', 37006, 'BCEXT'),
    (N'BANOBRAS', 37009, 'BOBRA'),
    (N'BANJERCITO', 37019, 'BEJER'),
    (N'NACIONAL FINANCIERA', 37135, 'NAFIN'),
    (N'HIPOTECARIA FEDERAL', 37168, 'HIFED'),
    (N'BANAMEX', 40002, 'BANAM'),
    (N'BBVA BANCOMER', 40012, 'BACOM'),
    (N'HSBC', 40021, 'BITAL'),
    (N'INBURSA', 40036, 'BINBU'),
    (N'BANREGIO', 40058, 'BANRE'),
    (N'INVEX', 40059, 'BINVE'),
    (N'BANSI', 40060, 'BANSI'),
    (N'BANORTE', 40072, 'BBANO'),
    (N'BANK OF AMERICA', 40106, 'BAMSA'),
    (N'MUFG BANK MEXICO', 40108, 'MUFG'),
    (N'BANCO MONEX', 40112, 'CMCA'),
    (N'VE POR MAS', 40113, 'DRESD'),
    (N'CREDIT SUISSE', 40126, 'CRESU'),
    (N'BANCO AUTOFIN', 40128, 'BAUTO'),
    (N'BARCLAYS BANK', 40129, 'BARCL'),
    (N'BANCO COMPARTAMOS', 40130, 'BCOMP'),
    (N'BANCO AMIGO (ABC CAPITAL)', 40138, 'AMIGO'),
    (N'CONSUBANCO (BANCO FACIL)', 40140, 'FACIL'),
    (N'VOLKSWAGEN BANK', 40141, 'VOLKS'),
    (N'CIBANCO (BANCO CONSULTORIA)', 40143, 'CONSU'),
    (N'BBASE', 40143, 'BBASE'),
    (N'BANKAOOL', 40147, 'AGROF'),
    (N'PAGATODO', 40148, 'PTODO'),
    (N'INMOBILIARIO', 40150, 'INMOB'),
    (N'DONDE', 40151, 'DONDE'),
    (N'BANCO FANTIERRA', 40154, 'FINTE'),
    (N'ICBC', 40155, 'ICBCH'),
    (N'SABADELL', 40156, 'SABAD'),
    (N'SHINAN', 40157, 'SHINH'),
    (N'MIZUHO BANK', 40158, 'MISUO'),
    (N'BANCO S3', 40160, 'BCOS3'),
    (N'MONEX CASA DE BOLSA', 90600, '90600'),
    (N'GBM CASA DE BOLSA', 90601, '90601'),
    (N'MASARI CASA DE BOLSA', 90602, '90602'),
    (N'VALUE CASA DE BOLSA', 90605, '90605'),
    (N'VECTOR CASA DE BOLSA', 90608, '90608'),
    (N'MULTIVA CBOLSA', 90613, '90613'),
    (N'FINAMEX', 90616, '90616'),
    (N'VALMEX (VALORES MEXICANOS CASA DE BOLSA', 90617, '90617'),
    (N'PROFUTURO GNP AFORE', 90620, '90620'),
    (N'INTERCAM CASA DE BOLSA', 90630, '90630'),
    (N'FINCOMUN SERVICIOS FINANCIEROS COMUNITARIOS', 90634, '90634'),
    (N'TACTIV CB', 90648, '90648'),
    (N'CREDICAPITAL', 90652, '90652'),
    (N'KUSPIT', 90653, '90653'),
    (N'UNAGRA', 90656, '90656'),
    (N'ASP INTEGRA OPC', 90659, '90659'),
    (N'LIBERTAD', 90670, '90670'),
    (N'CAJA POP MEXICANA', 90677, '90677'),
    (N'CRISTOBAL COLON', 90680, '90680'),
    (N'CAJA TELEFONIST', 90683, '90683'),
    (N'OPM (OPERADORA DE PAGOS MOVILES)', 90684, '90684'),
    (N'FONDO FIRA', 90685, '90685'),
    (N'INVERCAP', 90686, '90686'),
    (N'FOMPED', 90689, '90689'),
    (N'ARCUS', 90706, 'ARCUS'),
    (N'CLS BANK', 90901, 'CLSB'),
    (N'INDEVAL', 90902, '90902'),
    (N'SANTANDER', 40014, 'BANME'),
    (N'BANCO DEL BAJIO', 40030, 'BAJIO'),
    (N'BANCO MIFEL', 40042, 'MIFEL'),
    (N'SCOTIABANK', 40044, 'COMER'),
    (N'BANCO AFIRME', 40062, 'BAFIR'),
    (N'JP MORGAN S.A', 40110, 'CHASE'),
    (N'BANCO AZTECA', 40127, 'BAZTE'),
    (N'BANCO MULTIVA S.A.', 40132, 'MULTI'),
    (N'BANCO ACTINVER SA', 40133, 'PRUDE'),
    (N'BANCOPPEL S.A.', 40137, 'COPEL'),
    (N'CI BANCO', 90631, '90631'),
    (N'BANCREA SA', 40152, 'BCREA'),
    (N'BANSEFI', 37166, 'BANSE'),
    (N'AKALA SA DE CV SOCIEDAD FINANCIERA POPUL', 90638, '90638'),
    (N'SISTEMA DE TRANSFERENCIAS Y PAGOS SOFOM', 90646, '90646')
) AS v(nombre, no_inst, clave_transfer)
WHERE NOT EXISTS (SELECT 1 FROM [TG].[dbo].[bank_account_banks] b WHERE b.nombre = v.nombre);
GO

-- Solicitudes --------------------------------------------------
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'bank_account_requests')
BEGIN
    CREATE TABLE [TG].[dbo].[bank_account_requests] (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        user_id             INT           NOT NULL,   -- TG.dbo.Usuario.Id (solicitante)
        empresa_cod         INT           NOT NULL,   -- SG12.dbo.Empresas.cod (RAZON SOCIAL)
        banco               VARCHAR(100)  NOT NULL,   -- bank_account_banks.nombre
        clave_banco         VARCHAR(20)   NOT NULL,   -- clave transfer del banco (CLAVE_BANCO)
        moneda              VARCHAR(10)   NOT NULL,   -- PESOS | DOLARES
        tipo_cuenta         VARCHAR(10)   NOT NULL,   -- EXTRNA | TARJETA
        num_cuenta          VARCHAR(50)   NOT NULL,
        proveedor           VARCHAR(200)  NOT NULL,   -- nombre libre
        plaza_banxico       VARCHAR(10)   NOT NULL,
        sucursal            VARCHAR(10)   NOT NULL,
        tipo_cuenta_cod     VARCHAR(5)    NOT NULL,   -- TIPO_CUENTA numerico (40)
        benef_app_paterno   VARCHAR(60)   NULL,
        benef_app_materno   VARCHAR(60)   NULL,
        benef_log           VARCHAR(200)  NOT NULL,   -- LOG
        benef_direccion     VARCHAR(200)  NULL,
        benef_ciudad        VARCHAR(100)  NOT NULL,
        departamento        VARCHAR(40)   NOT NULL,
        fecha_solicitud     DATE          NOT NULL,
        fecha_alta          DATE          NULL,       -- la pone Tesoreria al aprobar
        status              INT           NOT NULL DEFAULT 1,
        id_beneficiario     VARCHAR(20)   NULL,       -- opcional, lo captura Tesoreria
        catalogo_cuenta_id  INT           NULL,       -- CatalogosCuentasBancarias.Id creado o ligado
        decided_by          INT           NULL,
        decided_at          DATETIME      NULL,
        comentario          VARCHAR(400)  NULL,       -- motivo de rechazo / nota de aprobacion
        file_path           VARCHAR(300)  NOT NULL DEFAULT '',  -- caratula del estado de cuenta
        file_extension      VARCHAR(10)   NOT NULL DEFAULT '',
        original_filename   VARCHAR(255)  NOT NULL DEFAULT '',
        file_size           INT           NOT NULL DEFAULT 0,
        created_at          DATETIME      NOT NULL DEFAULT GETDATE()
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_bank_account_requests_status')
    CREATE INDEX IX_bank_account_requests_status ON [TG].[dbo].[bank_account_requests] (status);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_bank_account_requests_user')
    CREATE INDEX IX_bank_account_requests_user ON [TG].[dbo].[bank_account_requests] (user_id);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_bank_account_requests_cuenta')
    CREATE INDEX IX_bank_account_requests_cuenta ON [TG].[dbo].[bank_account_requests] (num_cuenta);
GO
