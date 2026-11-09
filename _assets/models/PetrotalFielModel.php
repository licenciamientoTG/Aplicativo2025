<?php
class PetrotalFielModel extends Model {

    private function cifrar(string $texto): string {
        $iv = openssl_random_pseudo_bytes(16);
        $cifrado = openssl_encrypt($texto, 'AES-256-CBC', PETROTAL_FIEL_ENCRYPTION_KEY, 0, $iv);
        return base64_encode($iv . $cifrado);
    }

    private function descifrar(string $textoCifrado): string {
        $datos = base64_decode($textoCifrado);
        $iv = substr($datos, 0, 16);
        $cifrado = substr($datos, 16);
        return openssl_decrypt($cifrado, 'AES-256-CBC', PETROTAL_FIEL_ENCRYPTION_KEY, 0, $iv);
    }

    // Solo una configuración activa a la vez (spec: "se sobreescribe al
    // resubir"). Se borra la anterior antes de insertar la nueva dentro de
    // una transacción para no dejar el módulo sin configuración si el
    // insert falla a medio camino.
    //
    // La tabla vive en TG (creada por la migración del Task 1 sin pasar por
    // Model::connect), pero este modelo conecta a SG12 en su constructor
    // (Model::__construct, igual que PetrotalObligacionModel) — hay que
    // calificar el nombre con TG.dbo. explícitamente en cada consulta.
    //
    // MySqlPdoHandler::delete() exige params no vacíos (igual que update()),
    // así que el DELETE sin condiciones reales usa "WHERE 1 = ?" con [1]
    // en vez de un arreglo vacío; también usamos delete() en vez de update()
    // porque update() exige que el texto de la consulta contenga la palabra
    // "update" (chequeo por stristr), cosa que un DELETE no cumple.
    // Las columnas de ApiReportes (ApiKeyCifrada, ObligacionId, Ambiente) se
    // agregan con docs/sql/2026-11-08_petrotal_apireportes.sql. Mientras no
    // existan, el módulo sigue funcionando solo con la FIEL.
    private function tiene_columnas_api(): bool {
        $rows = $this->sql->select("SELECT COL_LENGTH('TG.dbo.PetrotalFielConfig', 'ApiKeyCifrada') AS L", []);
        return !empty($rows[0]['L']);
    }

    public function guardar_config(string $rutaCer, string $rutaKey, string $password, int $usuarioId): bool {
        $passwordCifrado = $this->cifrar($password);
        $conApi = $this->tiene_columnas_api();
        // Resubir la FIEL no debe borrar la ApiKey ya configurada.
        $previa = $conApi ? ($this->sql->select("SELECT TOP 1 ApiKeyCifrada, ObligacionId, Ambiente FROM TG.dbo.PetrotalFielConfig ORDER BY UpdatedAt DESC", [])[0] ?? null) : null;

        $this->sql->beginTransaction();
        try {
            $this->sql->delete("DELETE FROM TG.dbo.PetrotalFielConfig WHERE 1 = ?", [1]);
            if ($conApi) {
                $this->sql->insert(
                    "INSERT INTO TG.dbo.PetrotalFielConfig (RutaCer, RutaKey, PasswordCifrado, ActualizadoPor, ApiKeyCifrada, ObligacionId, Ambiente) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$rutaCer, $rutaKey, $passwordCifrado, $usuarioId, $previa['ApiKeyCifrada'] ?? null, $previa['ObligacionId'] ?? null, $previa['Ambiente'] ?? null]
                );
            } else {
                $this->sql->insert(
                    "INSERT INTO TG.dbo.PetrotalFielConfig (RutaCer, RutaKey, PasswordCifrado, ActualizadoPor) VALUES (?, ?, ?, ?)",
                    [$rutaCer, $rutaKey, $passwordCifrado, $usuarioId]
                );
            }
            $this->sql->commit();
            return true;
        } catch (Exception $e) {
            $this->sql->rollBack();
            return false;
        }
    }

    public function obtener_config(): ?array {
        $rows = $this->sql->select("SELECT TOP 1 * FROM TG.dbo.PetrotalFielConfig ORDER BY UpdatedAt DESC", []);
        if (!$rows) return null;
        $apiKeyCifrada = $rows[0]['ApiKeyCifrada'] ?? null;
        return [
            'ruta_cer' => $rows[0]['RutaCer'],
            'ruta_key' => $rows[0]['RutaKey'],
            'password' => $this->descifrar($rows[0]['PasswordCifrado']),
            'api_key' => $apiKeyCifrada ? $this->descifrar($apiKeyCifrada) : null,
            'obligacion_id' => isset($rows[0]['ObligacionId']) ? (int) $rows[0]['ObligacionId'] : null,
            'ambiente' => $rows[0]['Ambiente'] ?? null,
        ];
    }

    public function api_disponible(): bool {
        return $this->tiene_columnas_api();
    }

    // Guarda ApiKey/obligación/ambiente sobre la configuración existente
    // (requiere que ya se haya subido la FIEL).
    public function guardar_api(string $apiKey, int $obligacionId, string $ambiente): bool {
        if (!$this->tiene_columnas_api()) return false;
        return (bool) $this->sql->update(
            "UPDATE TG.dbo.PetrotalFielConfig SET ApiKeyCifrada = ?, ObligacionId = ?, Ambiente = ?, UpdatedAt = GETDATE()",
            [$this->cifrar($apiKey), $obligacionId, $ambiente]
        );
    }
}
