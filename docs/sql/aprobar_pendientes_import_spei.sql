-- Aprueba en bloque las solicitudes importadas del Excel SPEI que siguen Pendientes de Tesorería.
-- 533 se aprueban; 31 se quedan Pendientes para revisión manual (ver lista al final).
-- Igual que Aprobar (BankAccountRequestsModel::approve): alta en CatalogosCuentasBancarias como Terceros activa,
-- liga catalogo_cuenta_id, razones sociales a bank_account_payers. Aprobó: alejandro.martinez (6296).
-- FechaAlta = la fecha alta del Excel cuando la trae; si no, hoy.
SET NOCOUNT ON; SET XACT_ABORT ON;
USE [TG];
BEGIN TRAN;

DECLARE @uid INT = (SELECT Id FROM dbo.Usuario WHERE Id = 6296 AND Usuario = 'alejandro.martinez');
IF @uid IS NULL THROW 50001, 'No existe el usuario alejandro.martinez (Id 6296).', 1;
DECLARE @ids TABLE (id INT PRIMARY KEY);
INSERT INTO @ids VALUES (9790),(9797),(9802),(9821),(9822),(9831),(9865),(9866),(9918),(9941),(9960),(9966),(9988),(10004),(10012),(10018),(10035),(10036),(10037),(10041),(10042),(10051),(10060),(10066),(10149),(10162),(10163),(10171),(10174),(10186),(10189),(10190),(10193),(10194),(10195),(10211),(10249),(10251),(10261),(10264),(10269),(10270),(10295),(10326),(10357),(10377),(10379),(10380),(10436),(10453),(10460),(10469),(10473),(10481),(10497),(10508),(10511),(10546),(10547),(10550),(10551),(10561),(10574),(10581),(10607),(10610),(10649),(10685),(10686),(10690),(10693),(10697),(10698),(10705),(10710),(10734),(10736),(10738),(10739),(10744),(10747),(10757),(10759),(10760),(10763),(10768),(10771),(10772),(10776),(10778),(10779),(10786),(10788),(10791),(10794),(10795),(10796),(10799),(10800),(10810),(10819),(10821),(10823),(10827),(10831),(10835),(10843),(10845),(10854),(10859),(10870),(10899),(10929),(10942),(10959),(10967),(10969),(10970),(10971),(10975),(10977),(10978),(10985),(10993),(10994),(10998),(11000),(11003),(11007),(11009),(11012),(11013),(11016),(11037),(11044),(11071),(11075),(11076),(11082),(11116),(11117),(11122),(11134),(11137),(11149),(11156),(11176),(11192),(11195),(11198),(11200),(11201),(11205),(11206),(11209),(11210),(11211),(11212),(11213),(11215),(11216),(11218),(11219),(11247),(11266),(11275),(11280),(11282),(11296),(11308),(11316),(11324),(11337),(11360),(11361),(11365),(11374),(11375),(11382),(11388),(11389),(11390),(11391),(11402),(11404),(11405),(11407),(11411),(11413),(11430),(11431),(11439),(11444),(11452),(11454),(11460),(11474),(11505),(11512),(11521),(11523),(11538),(11547),(11548),(11558),(11573),(11574),(11581),(11584),(11590),(11592),(11595),(11596),(11597),(11598),(11646),(11660),(11692),(11741),(11758),(11777),(11786),(11803),(11804),(11805),(11807),(11809),(11811),(11814),(11817),(11818),(11820),(11821),(11846),(11849),(11855),(11856),(11859),(11868),(11871),(11872),(11879),(11887),(11891),(11906),(11921),(11932),(11944),(11946),(11975),(11977),(11978),(11979),(11980),(11994),(11995),(11998),(12003),(12018),(12023),(12058),(12062),(12072),(12074),(12083),(12101),(12108),(12111),(12118),(12137),(12182),(12186),(12191),(12192),(12193),(12197),(12198),(12204),(12205),(12217),(12219),(12221),(12235),(12239),(12248),(12257),(12258),(12262),(12265),(12276),(12286),(12309),(12311),(12322),(12324),(12350),(12351),(12353),(12400),(12401),(12402),(12403),(12417),(12418),(12422),(12448),(12449),(12450),(12452),(12456),(12462),(12473),(12481),(12489),(12496),(12497),(12500),(12502),(12506),(12516),(12517),(12519),(12528),(12532),(12538),(12546),(12557),(12558),(12559),(12562),(12564),(12569),(12581),(12582),(12584),(12585),(12587),(12590),(12592),(12593),(12594),(12595),(12602),(12603),(12604),(12605),(12608),(12611),(12613),(12619),(12627),(12630),(12632),(12638),(12642),(12644),(12646),(12649),(12663),(12667),(12669),(12670),(12673),(12677),(12680),(12683),(12685),(12691),(12693),(12694),(12698),(12703),(12706),(12709),(12711),(12713),(12716),(12723),(12726),(12731),(12738),(12740),(12743),(12744),(12745),(12746),(12747),(12748),(12749),(12767),(12770),(12771),(12775),(12776),(12777),(12784),(12786),(12791),(12800),(12808),(12810),(12811),(12812),(12814),(12817),(12819),(12820),(12822),(12825),(12826),(12829),(12831),(12834),(12835),(12836),(12841),(12845),(12853),(12860),(12861),(12863),(12864),(12865),(12866),(12867),(12871),(12874),(12875),(12882),(12888),(12890),(12895),(12896),(12899),(12902),(12903),(12908),(12911),(12912),(12913),(12915),(12918),(12919),(12921),(12923),(12926),(12927),(12928),(12937),(12939),(12941),(12947),(12948),(12949),(12957),(12962),(12965),(12967),(12969),(12970),(12971),(12972),(12994),(12995),(13012),(13013),(13014),(13019),(13021),(13023),(13024),(13027),(13028),(13029),(13030),(13031),(13032),(13034),(13036),(13038),(13039),(13041),(13043),(13045),(13046),(13048),(13049),(13050),(13051),(13052),(13053),(13054),(13058),(13060),(13061),(13065),(13066),(13069),(13072),(13073),(13074),(13078),(13083),(13084),(13086),(13087),(13097),(13101),(13106),(13115),(13120),(13121),(13126),(13132),(13134),(13136),(13137),(13138),(13140),(13141),(13143),(13144),(13145),(13147),(13148),(13149),(13150),(13154),(13156),(13157),(13158),(13160),(13166);

-- Solo las que siguen pendientes, son importadas y cuya cuenta aún no está en el catálogo
SELECT r.id, r.num_cuenta, r.clabe, r.proveedor, r.banco, r.moneda, r.benef_direccion,
       COALESCE(TRY_CONVERT(DATE, SUBSTRING(r.comentario, NULLIF(CHARINDEX('fecha alta ', r.comentario), 0) + 11, 10)), CAST(GETDATE() AS DATE)) AS falta
  INTO #ap
  FROM dbo.bank_account_requests r JOIN @ids i ON i.id = r.id
 WHERE r.status = 1 AND r.comentario LIKE N'Importada de SOLICITUD ALTA%'
   AND NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias c
                    WHERE c.CuentaLocal = r.num_cuenta OR c.Clave = r.num_cuenta
                       OR (r.clabe IS NOT NULL AND (c.CuentaLocal = r.clabe OR c.Clave = r.clabe)));

DECLARE @alta TABLE (cat_id INT, cuenta VARCHAR(50));
INSERT INTO dbo.CatalogosCuentasBancarias
    (FechaAlta, CuentaLocal, Clave, Descripcion, TipoCuenta, Banco, Divisa,
     TitularCuenta, Tipo, Direccion, Activo, FechaRegistro, UsuarioRegistro, EntidadCuentaLocalABASwift)
OUTPUT inserted.Id, inserted.CuentaLocal INTO @alta
SELECT a.falta, a.num_cuenta, a.clabe, a.proveedor, 'Otros Bancos Nacionales', a.banco,
       CASE WHEN a.moneda = 'DOLARES' THEN 'DOLAR AMERICANO' ELSE 'NUEVO PESO MEXICANO' END,
       a.proveedor, 'Terceros', NULLIF(a.benef_direccion, ''), 1, GETDATE(), CAST(@uid AS VARCHAR(20)), NULL
  FROM #ap a;

UPDATE r SET status = 2, decided_by = @uid, decided_at = GETDATE(), fecha_alta = a.falta, catalogo_cuenta_id = t.cat_id,
       comentario = LEFT(r.comentario + N'; aprobada en bloque 2026-11-17', 400)
  FROM dbo.bank_account_requests r JOIN #ap a ON a.id = r.id JOIN @alta t ON t.cuenta = a.num_cuenta;

INSERT INTO dbo.bank_account_payers (catalogo_cuenta_id, emp_cod, created_by)
SELECT DISTINCT t.cat_id, p.emp_cod, @uid
  FROM #ap a JOIN @alta t ON t.cuenta = a.num_cuenta JOIN dbo.bank_account_request_payers p ON p.request_id = a.id
 WHERE NOT EXISTS (SELECT 1 FROM dbo.bank_account_payers x WHERE x.catalogo_cuenta_id = t.cat_id AND x.emp_cod = p.emp_cod);

SELECT (SELECT COUNT(*) FROM #ap) AS a_aprobar, (SELECT COUNT(*) FROM @alta) AS altas_catalogo,
       (SELECT COUNT(*) FROM dbo.bank_account_requests WHERE comentario LIKE N'%aprobada en bloque 2026-11-17') AS aprobadas,
       (SELECT COUNT(*) FROM dbo.bank_account_requests WHERE status = 1 AND comentario LIKE N'Importada de SOLICITUD ALTA%') AS siguen_pendientes;
-- Revise los conteos y cambie a COMMIT cuando estén bien:
COMMIT;

/* Se quedan Pendientes para revisar a mano:
   #9801 VICTOR LEONARDO RAMOS SALCIDO · BANAMEX · 00164702139606423 · PESOS -> cuenta de 17 dígitos (¿CLABE incompleta?)
   #10735 GIOVANNA AIDEE LOPEZ LOPEZ · BBVA BANCOMER · 012164004893748142 · PESOS -> CLABE con dígito verificador inválido
   #10782 JESUS FRANCISCO IBARRA MORQUECHO · BBVA BANCOMER · 01216401512700021 · PESOS -> cuenta de 17 dígitos (¿CLABE incompleta?)
   #11159 RAUL AMAVISCA VIEZCAS · BBVA BANCOMER · 01276015487474200 · PESOS -> cuenta de 17 dígitos (¿CLABE incompleta?)
   #11396 COMERCIALIZADORA Y DISTRIBUIDORA ISPAVON · SANTANDER · 01416455090060120 · PESOS -> cuenta de 17 dígitos (¿CLABE incompleta?)
   #11576 DIAZ GAS SA DE CV / SANTANDER · SANTANDER · 014650655056751178 · PESOS -> parece cuenta propia (va como Propias, no Terceros)
   #11774 PROVEEDORA INDUSTRIAL MYM · HSBC · 02116404067733292 · PESOS -> cuenta de 17 dígitos (¿CLABE incompleta?)
   #11779 ALEJANDRO RIVERA QUEZADA · HSBC · 021164060677331210 · PESOS -> CLABE con dígito verificador inválido
   #11885 CENTER FOR QUALITY ASSURANCE · BANK OF AMERICA · 026009593 · DOLARES -> el número parece routing number (ABA), no cuenta
   #12013 VEREDICTOS CIENTIFICOS S.C. · INBURSA · 03615050026265581 · PESOS -> cuenta de 17 dígitos (¿CLABE incompleta?)
   #12112 URBE ARQUITECTURA Y CONSTRUCCION · SCOTIABANK · 04415215039985334 · PESOS -> cuenta de 17 dígitos (¿CLABE incompleta?)
   #12194 DISTRIBUIDORA GASOMEX SA DE CV · SCOTIABANK · 044164226068046753 · PESOS -> parece cuenta propia (va como Propias, no Terceros)
   #12291 GRUAS Y MANIOBRAS PESA · BANREGIO · 058164578572000104 · PESOS -> CLABE con dígito verificador inválido
   #12299 SMARTMEDIA PROYECTOS CORPORATIVOS · BANREGIO · 05816465013900192 · PESOS -> cuenta de 17 dígitos (¿CLABE incompleta?)
   #12598 EDGAR LIMON D'OPORTO · BANORTE · 072164004624881893 · PESOS -> CLABE con dígito verificador inválido
   #12606 DIAZ GAS 0947 BANORTE · BANORTE · 072164006015009472 · PESOS -> parece cuenta propia (va como Propias, no Terceros)
   #12652 ZAIDENERGY SC 1752 · BANORTE · 072164011565717526 · PESOS -> parece cuenta propia (va como Propias, no Terceros)
   #12750 MARCOS GIOVANNI FERNANDEZ FLORES · BANORTE · 072164613051837756 · PESOS -> CLABE con dígito verificador inválido
   #12802 LOGICA INTERACTIVA · BANORTE · 072760001038455754 · DOLARES -> CLABE con dígito verificador inválido
   #12809 INTERNATIONAL FORWARDING LLC · SIN BANCO · 1008016055 · PESOS -> sin banco
   #12815 TRITON TRADING, INC · SIN BANCO · 107985337 · DOLARES -> sin banco
   #12832 COMPLIANCE Y CONDUCT ADVISORD · BBVA BANCOMER · 12180001104885342 · PESOS -> cuenta de 17 dígitos (¿CLABE incompleta?)
   #12833 OPTIMA COMMERCE, LLC. · JP MORGAN S.A · 122100024 · DOLARES -> el número parece routing number (ABA), no cuenta
   #12930 BUREAU VERITAS COMMODITIES AND TRADE, INC · SIN BANCO · 1453719822 · DOLARES -> sin banco
   #12934 DIAZ GAS BANKAOOL · BANKAOOL · 147152000000003698 · PESOS -> parece cuenta propia (va como Propias, no Terceros)
   #12950 SANTA FE ENERGY GROUP LLC · SIN BANCO · 2148070903 · DOLARES -> sin banco
   #12955 SERV ENERGY LLC · SIN BANCO · 2400028020 · DOLARES -> sin banco
   #12961 SANTA FE ENERGY GROUP LLC · SIN BANCO · 4716596493 · DOLARES -> sin banco
   #12964 KEY PERFORMANCE PETROLEUM · SIN BANCO · 503237699 · DOLARES -> sin banco
   #13015 Independent Commodity Trading LLC · SIN BANCO · 592448033 · DOLARES -> sin banco
   #13159 JPAC GLOBAL ACQUISITIONS GROUP, LLC · SIN BANCO · 765655771 · DOLARES -> sin banco
*/
