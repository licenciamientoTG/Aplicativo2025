-- Catálogo de departamentos organizacionales, separado de TG.dbo.Perfil (que
-- mezcla roles de acceso —Super Administrador, Administrador, Auxiliar,
-- Encargado Estación— con áreas reales de trabajo). Los 7 nombres que sí son
-- departamentos se migran aquí; los que son roles puros no se copian.
-- Usuario.IdDepartamento se puebla por Perfil.Nombre coincidente; el resto
-- queda NULL (se captura a mano desde el modal de edición de usuario).
USE [TG]
GO

CREATE TABLE [dbo].[Departamentos] (
    Id     INT IDENTITY(1,1) PRIMARY KEY,
    Nombre NVARCHAR(80) NOT NULL,
    Activo BIT          NOT NULL DEFAULT 1,
    CONSTRAINT UQ_Departamentos_Nombre UNIQUE (Nombre)
);
GO

INSERT INTO [dbo].[Departamentos] (Nombre) VALUES
    ('Capital Humano'),
    ('Dpto Administracion'),
    ('Logistica'),
    ('Direccion'),
    ('AdministracionDevolucion'),
    ('Comercial'),
    ('Operaciones');
GO

ALTER TABLE [dbo].[Usuario] ADD IdDepartamento INT NULL;
GO

-- Migración: el usuario hereda el departamento si su Perfil actual coincide
-- por nombre con uno de los 7 reales; el resto (roles de acceso puros) queda
-- NULL, capturable después desde el modal de edición de usuario.
UPDATE u
   SET u.IdDepartamento = d.Id
  FROM [dbo].[Usuario] u
  JOIN [dbo].[Perfil] p ON p.Id = u.IdPerfil
  JOIN [dbo].[Departamentos] d ON d.Nombre = p.Nombre;
GO
