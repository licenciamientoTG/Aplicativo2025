-- Alta de la estación Colosio (Repsol, Aguascalientes) en TG.dbo.Estaciones.
-- No está en ControlGas: TotalGas la administra pero la información llega
-- por otro medio, así que se captura manualmente en /merma/analisis
-- (botón "Captura manual (Colosio)"). Codigo=199 fuera del rango real de
-- estaciones (1-40) para no colisionar nunca con una estación futura.
-- Sin Servidor/BaseDatos: el módulo merma ya trata eso como "sin conexión
-- automática" (MermaDiariaModel::get_estacion_conexion()).

IF NOT EXISTS (SELECT 1 FROM TG.dbo.Estaciones WHERE Codigo = 199)
BEGIN
    INSERT INTO TG.dbo.Estaciones (Codigo, Nombre, Domicilio, Ciudad, activa)
    VALUES (
        199,
        'COLOSIO',
        'Avenida Monte Blanco, No. 604, Col. Fracc. Villas de San Nicolás, C.P. 20115',
        'Aguascalientes',
        1
    );
END
