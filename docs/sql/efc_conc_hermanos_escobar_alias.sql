/*
   Corrige las filas históricas de Hermanos Escobar al Codigo interno de TG
   (30; catálogo Estacion=E23214). El Python reconoce el par fuente
   codigo=1000 + nombre exacto; conserva ambos campos originales como evidencia.
   Ejecutar conectado a TG. Transacción e idempotente.
*/
SET XACT_ABORT ON;
BEGIN TRANSACTION;

DECLARE @estacion_id INT;
IF (SELECT COUNT(*) FROM dbo.Estaciones WHERE Estacion = 'E23214') <> 1
    THROW 51001, 'Se esperaba una sola estación de catálogo E23214.', 1;

SELECT @estacion_id = Codigo
FROM dbo.Estaciones
WHERE Estacion = 'E23214';

IF @estacion_id <> 30
    THROW 51002, 'El Codigo interno esperado para E23214 no es 30; no se aplicó el cambio.', 1;

/* Retira el alias solo por nombre creado durante la revisión previa; el Python
   final requiere que coincidan tanto el codigo fuente 1000 como el nombre. */
DELETE dbo.efc_conc_analiticos_alias_estacion
 WHERE alias_normalizado = 'ESTANCIAAVENIDAHERMANOSESCOBAR'
   AND estacion_id = @estacion_id;

UPDATE P
   SET estacion_id = @estacion_id,
       estado_estacion = 'IDENTIFICADA_ALIAS'
FROM dbo.efc_conc_analiticos_papeletas P
JOIN dbo.efc_conc_analiticos_importaciones I ON I.id = P.importacion_id
WHERE P.estacion_id IS NULL
  AND P.estacion_original = '1000.0'
  AND P.estacion_nombre_original = 'Estancia Avenida Hermanos Escobar'
  AND I.estado = 'IMPORTADA';

SELECT @@ROWCOUNT AS papeletas_reasignadas;

COMMIT TRANSACTION;

/*
   Rollback (solo si aún no se han creado vínculos/correcciones para estas
   papeletas): restaura su estado sin estación.
   Si la validación detecta vínculos o correcciones, detenerse y revisar primero.

SET XACT_ABORT ON;
BEGIN TRANSACTION;
IF EXISTS (
    SELECT 1
    FROM dbo.efc_conc_analiticos_papeletas P
    WHERE P.estacion_id = 30
      AND P.estado_estacion = 'IDENTIFICADA_ALIAS'
      AND P.estacion_original = '1000.0'
      AND P.estacion_nombre_original = 'Estancia Avenida Hermanos Escobar'
      AND (
          EXISTS (SELECT 1 FROM dbo.efc_conc_analiticos_vinculos V WHERE (V.papeleta_id=P.id OR V.papeleta_secundaria_id=P.id) AND V.activo=1)
          OR EXISTS (SELECT 1 FROM dbo.efc_conc_analiticos_correcciones_estacion C WHERE C.papeleta_id=P.id AND C.activo=1)
      )
)
    THROW 51003, 'Hay papeletas vinculadas o con corrección de estación; revise antes de revertir.', 1;

UPDATE dbo.efc_conc_analiticos_papeletas
   SET estacion_id = NULL,
       estado_estacion = 'ESTACION_NO_IDENTIFICADA'
 WHERE estacion_id = 30
   AND estado_estacion = 'IDENTIFICADA_ALIAS'
   AND estacion_original = '1000.0'
   AND estacion_nombre_original = 'Estancia Avenida Hermanos Escobar';

COMMIT TRANSACTION;
*/
