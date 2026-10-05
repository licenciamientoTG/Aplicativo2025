<?php
class ControlgasUsersModel extends Model {

    private const BACKUP_TABLE = '[TG].[dbo].[ControlgasUserDisableBackup]';
    private const DISABLED_PASSWORD = 'O5fz43Stf5x2C5W7j3CcCrWGjcWHCdMB';
    private const RESTORE_FIELDS = [
        'acc', 'accx', 'codrol', 'codest', 'serfac', 'mdafac', 'efefac', 'acc2', 'accx2',
    ];

    /** Disable one user and return the legacy single-user response shape. */
    public function disable_user(int $cod): array {
        $result = $this->disable_users([$cod]);
        return !empty($result['success'])
            ? ['success' => true, 'message' => 'Usuario deshabilitado']
            : [
                'success' => false,
                'message' => $result['message'] ?? 'No se pudo deshabilitar el usuario',
            ];
    }

    /**
     * Return the matching users and missing COD values for a validated list.
     */
    public function find_users_by_codes(array $cods): array {
        if (!$cods) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($cods), '?'));
        $query = "SELECT [cod], [den]
                  FROM [SG12].[dbo].[Usuarios]
                  WHERE [cod] IN ($placeholders)";
        $rows = $this->sql->select($query, $cods) ?: [];
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[(int)$row['cod']] = [
                'COD' => (int)$row['cod'],
                'DEN' => (string)$row['den'],
            ];
        }

        $users = [];
        $missing = [];
        foreach ($cods as $cod) {
            if (isset($byCode[$cod])) {
                $users[] = $byCode[$cod];
            } else {
                $missing[] = $cod;
            }
        }
        return ['users' => $users, 'missing' => $missing];
    }

    /**
     * Snapshot all values before disabling users. Backup and SG12 share the
     * connection, so both changes commit or roll back together.
     */
    public function disable_users(array $cods): array {
        if (!$cods) {
            return ['success' => false, 'message' => 'No hay usuarios para deshabilitar'];
        }

        $connection = $this->sql->getConnection();
        if (!$connection) {
            return ['success' => false, 'message' => 'No se pudieron deshabilitar los usuarios'];
        }

        try {
            $this->sql->beginTransaction();

            $placeholders = implode(', ', array_fill(0, count($cods), '?'));
            $sourceFields = '[cod], [den], [clv], ' . implode(', ', array_map(
                static fn($field) => '[' . $field . ']', self::RESTORE_FIELDS
            ));
            $users = $this->sql->selectSafe(
                "SELECT $sourceFields
                 FROM [SG12].[dbo].[Usuarios] WITH (UPDLOCK, HOLDLOCK)
                 WHERE [cod] IN ($placeholders)",
                $cods
            );
            if ($users === false || count($users) !== count($cods)) {
                throw new RuntimeException('No se encontraron todos los usuarios');
            }

            $backups = $this->sql->selectSafe(
                "SELECT [cod], [is_active]
                 FROM " . self::BACKUP_TABLE . " WITH (UPDLOCK, HOLDLOCK)
                 WHERE [cod] IN ($placeholders)",
                $cods
            );
            if ($backups === false) {
                $this->sql->rollBack();
                return [
                    'success' => false,
                    'message' => 'No se pudo acceder al respaldo de TG. Ejecuta primero el SQL de creación de la tabla.',
                ];
            }
            $backupByCode = [];
            foreach ($backups as $backup) {
                $backupByCode[(int)$backup['cod']] = $backup;
            }

            foreach ($users as $user) {
                $code = (int)$user['cod'];
                if (strncmp((string)$user['den'], 'BAJA ', 5) === 0) {
                    throw new RuntimeException('No se puede respaldar un usuario que ya está deshabilitado');
                }
                if (isset($backupByCode[$code]) && (bool)$backupByCode[$code]['is_active']) {
                    throw new RuntimeException('Ya existe un respaldo activo para un usuario solicitado');
                }
                $values = [
                    $code,
                    $user['den'], $user['clv'],
                    $user['acc'], $user['accx'], $user['codrol'], $user['codest'],
                    $user['serfac'], $user['mdafac'], $user['efefac'], $user['acc2'], $user['accx2'],
                ];
                if (isset($backupByCode[$code])) {
                    $updated = $this->sql->updateSafe(
                        "UPDATE " . self::BACKUP_TABLE . "
                         SET [den_original] = ?, [clv_original] = ?, [acc_original] = ?,
                             [accx_original] = ?, [codrol_original] = ?, [codest_original] = ?,
                             [serfac_original] = ?, [mdafac_original] = ?, [efefac_original] = ?,
                             [acc2_original] = ?, [accx2_original] = ?, [is_active] = 1,
                             [captured_at] = SYSUTCDATETIME()
                         WHERE [cod] = ? AND [is_active] = 0",
                        array_merge(array_slice($values, 1), [$code])
                    );
                    if ($updated !== 1) {
                        throw new RuntimeException('No se pudo actualizar el respaldo');
                    }
                } else {
                    $insertSql = "INSERT INTO " . self::BACKUP_TABLE . "
                        ([cod], [den_original], [clv_original], [acc_original], [accx_original],
                         [codrol_original], [codest_original], [serfac_original], [mdafac_original],
                         [efefac_original], [acc2_original], [accx2_original], [is_active], [captured_at])
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, SYSUTCDATETIME())";
                    $statement = $connection->prepare($insertSql);
                    if (!$statement || !$statement->execute($values)) {
                        throw new RuntimeException('No se pudo crear el respaldo');
                    }
                    $statement->closeCursor();
                }
            }

            $updated = $this->sql->updateSafe(
                "UPDATE [SG12].[dbo].[Usuarios]
                 SET [clv] = ?,
                     [den] = 'BAJA ' + [den],
                     [acc] = 0, [accx] = 0, [codrol] = 0, [codest] = 0,
                     [serfac] = 0, [mdafac] = 0, [efefac] = 0, [acc2] = 0, [accx2] = 0
                 WHERE [cod] IN ($placeholders) AND LEFT([den], 5) <> 'BAJA '",
                array_merge([self::DISABLED_PASSWORD], $cods)
            );
            if ($updated === false || $updated !== count($cods)) {
                throw new RuntimeException('No se actualizaron todos los usuarios');
            }

            $this->sql->commit();
            return ['success' => true, 'updated' => (int)$updated];
        } catch (Throwable $e) {
            $this->sql->rollBack();
            error_log('Controlgas user disable failed: ' . $e->getMessage());
            // RuntimeException messages above are deliberately written as
            // user-safe explanations (missing users, already disabled users,
            // row-count mismatches, etc.). Preserve those instead of hiding
            // every bulk failure behind the same generic response. Keep raw
            // database/runtime details in the server log only.
            $message = get_class($e) === RuntimeException::class
                ? $e->getMessage()
                : 'Ocurrió un error al guardar los respaldos o actualizar SG12. Revisa el log del servidor.';
            return ['success' => false, 'message' => $message];
        }
    }

    /** Restore a previously snapshotted user and retire its backup. */
    public function rehabilitate_user(int $cod): array {
        if ($cod <= 0) {
            return ['success' => false, 'message' => 'Código inválido'];
        }

        try {
            $this->sql->beginTransaction();
            $backups = $this->sql->selectSafe(
                "SELECT [cod], [den_original], [clv_original], [acc_original], [accx_original],
                        [codrol_original], [codest_original], [serfac_original], [mdafac_original],
                        [efefac_original], [acc2_original], [accx2_original]
                 FROM " . self::BACKUP_TABLE . " WITH (UPDLOCK, HOLDLOCK)
                 WHERE [cod] = ? AND [is_active] = 1",
                [$cod]
            );
            if ($backups === false) {
                $this->sql->rollBack();
                return [
                    'success' => false,
                    'message' => 'No se pudo acceder al respaldo de TG. Ejecuta primero el SQL de creación de la tabla.',
                ];
            }
            if (!$backups) {
                $this->sql->rollBack();
                return [
                    'success' => false,
                    'message' => 'Este usuario no tiene un respaldo anterior en TG y no se puede rehabilitar automáticamente.',
                ];
            }
            $backup = $backups[0];

            $users = $this->sql->selectSafe(
                "SELECT [cod], [den]
                 FROM [SG12].[dbo].[Usuarios] WITH (UPDLOCK, HOLDLOCK)
                 WHERE [cod] = ? AND LEFT([den], 5) = 'BAJA '",
                [$cod]
            );
            if ($users === false || count($users) !== 1) {
                throw new RuntimeException('El usuario no está deshabilitado en SG12');
            }

            $restoreParams = [
                $backup['den_original'], $backup['clv_original'],
                $backup['acc_original'], $backup['accx_original'], $backup['codrol_original'],
                $backup['codest_original'], $backup['serfac_original'], $backup['mdafac_original'],
                $backup['efefac_original'], $backup['acc2_original'], $backup['accx2_original'], $cod,
            ];
            $restored = $this->sql->updateSafe(
                "UPDATE [SG12].[dbo].[Usuarios]
                 SET [den] = ?, [clv] = ?, [acc] = ?, [accx] = ?, [codrol] = ?, [codest] = ?,
                     [serfac] = ?, [mdafac] = ?, [efefac] = ?, [acc2] = ?, [accx2] = ?
                 WHERE [cod] = ? AND LEFT([den], 5) = 'BAJA '",
                $restoreParams
            );
            if ($restored !== 1) {
                throw new RuntimeException('No se pudo restaurar el usuario');
            }

            $cleared = $this->sql->updateSafe(
                "UPDATE " . self::BACKUP_TABLE . "
                 SET [den_original] = '', [clv_original] = '', [acc_original] = 0,
                     [accx_original] = 0, [codrol_original] = 0, [codest_original] = 0,
                     [serfac_original] = 0, [mdafac_original] = 0, [efefac_original] = 0,
                     [acc2_original] = 0, [accx2_original] = 0,
                     [is_active] = 0, [captured_at] = NULL
                 WHERE [cod] = ? AND [is_active] = 1",
                [$cod]
            );
            if ($cleared !== 1) {
                throw new RuntimeException('No se pudo cerrar el respaldo');
            }

            $this->sql->commit();
            return ['success' => true, 'message' => 'Usuario rehabilitado'];
        } catch (Throwable $e) {
            $this->sql->rollBack();
            error_log('Controlgas user rehabilitation failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'No se pudo rehabilitar el usuario'];
        }
    }

    public function get_users(): array {
        $query = "SELECT TOP (1000)
                    users.[cod], users.[den], users.[clv], users.[acc], users.[accx],
                    users.[tipopr], users.[tipusu], users.[codrol], users.[codest],
                    users.[logusu], users.[logfch], users.[lognew], users.[userid],
                    users.[clvfch], users.[clvexp],
                    CASE WHEN snapshot.[cod] IS NULL THEN 0 ELSE 1 END AS [has_active_backup]
                  FROM [SG12].[dbo].[Usuarios] AS users
                  LEFT JOIN " . self::BACKUP_TABLE . " AS snapshot
                    ON snapshot.[cod] = users.[cod] AND snapshot.[is_active] = 1
                  ORDER BY users.[cod]";
        $rows = $this->sql->selectSafe($query);
        if ($rows !== false) {
            return $rows;
        }

        // Keep the table readable before the user-created TG backup table exists.
        $fallbackQuery = "SELECT TOP (1000)
                            [cod], [den], [clv], [acc], [accx],
                            [tipopr], [tipusu], [codrol], [codest],
                            [logusu], [logfch], [lognew], [userid],
                            [clvfch], [clvexp], 0 AS [has_active_backup]
                          FROM [SG12].[dbo].[Usuarios]
                          ORDER BY [cod]";
        return $this->sql->select($fallbackQuery) ?: [];
    }
}
