

<?php
class MojoTicketsModel extends Model{
    public $id;
    public $id_mojo;
    public $title;
    public $description;
    public $user_id;
    public $company_id;
    public $assigned_to_id;
    public $status_id;
    public $priority_id;
    public $ticket_queue_id;
    public $rating;
    public $rated_on;
    public $created_on;
    public $updated_on;
    public $status_changed_on;
    public $solved_on;
    public $assigned_on;
    public $created_from;
    public $ticket_type_id;
    public $cc;
    public $legacy_id;
    public $first_assigned_on;
    public $user_attention_id;
    public $due_on;
    public $scheduled_on;
    public $is_attention_required;
    public $ticket_form_id;
    public $resolution_id;
    public $first_commented_on;
    public $rating_comment;

    function get_tickets($from, $until) : array|false{
        $query = "SELECT
                        t1.*,
                        t2.name AS company,
                        ISNULL(t3.first_name, '') + ' ' + ISNULL(t3.middle_name, '') + ' ' + ISNULL(t3.last_name, '') username,
                        ISNULL(NULLIF(ISNULL(t4.first_name, '') + ' ' + ISNULL(t4.middle_name, '') + ' ' + ISNULL(t4.last_name, ''), '  '), 'Sin asignar') AS agent,
                        t5.name AS status,
                        t6.name AS priority,
                        t7.name AS queue,
                        ISNULL(CAST(t1.rating AS VARCHAR), 'N/A') AS rating,
                        ISNULL(CAST(t8.name AS VARCHAR), 'N/A') AS ticket_type,
                        t9.name AS ticket_form,
                        LEFT(t1.description, 100) + '...' AS truncated_description,
                        LEFT(t1.title, 50) + '...' AS truncated_title,
                        DATEDIFF(MINUTE, t1.created_on, t1.solved_on) / 60.0 AS hours_to_resolve
                    FROM
                        [TG].[dbo].[mojo_tickets] t1
                        LEFT JOIN [TG].[dbo].[mojo_companies] t2 ON t1.company_id = t2.id_mojo
                        LEFT JOIN [TG].[dbo].[mojo_users] t3 ON t1.user_id = t3.id_mojo
                        LEFT JOIN [TG].[dbo].[mojo_users] t4 ON t1.assigned_to_id = t4.id_mojo
                        LEFT JOIN [TG].[dbo].[mojo_ticket_status] t5 ON t1.status_id = t5.id_mojo
                        LEFT JOIN [TG].[dbo].[mojo_ticket_priority] t6 ON t1.priority_id = t6.id_mojo
                        LEFT JOIN [TG].[dbo].[mojo_ticket_queue] t7 ON t1.ticket_queue_id = t7.id_mojo
                        LEFT JOIN [TG].[dbo].[mojo_ticket_types] t8 ON t1.ticket_type_id = t8.id_mojo
                        LEFT JOIN [TG].[dbo].[mojo_ticket_forms] t9 ON t1.ticket_form_id = t9.id_mojo
                    WHERE
                        t1.created_on BETWEEN CONVERT(datetime, '{$from}', 102) AND CONVERT(datetime, '{$until}', 102)
                    ORDER BY
                        t1.created_on DESC
                ;";
        return $this->sql->select($query);
    }

    function get_tickets_report($from, $until, $ticket_form) : array|false {

        $from_sql = str_replace(' ', 'T', $from);
        $until_sql = str_replace(' ', 'T', $until);
        $query = "DECLARE @fechaInicio DATETIME = '{$from_sql}';
                DECLARE @fechaFin DATETIME = '{$until_sql}';

                -- Aseguramos que lunes sea 1 y domingo 7
                SET DATEFIRST 1;

                SELECT
                    t1.*,
                    t2.name AS company,
                    ISNULL(t3.first_name, '') + ' ' + ISNULL(t3.middle_name, '') + ' ' + ISNULL(t3.last_name, '') AS username,
                    ISNULL(NULLIF(ISNULL(t4.first_name, '') + ' ' + ISNULL(t4.middle_name, '') + ' ' + ISNULL(t4.last_name, ''), '  '), 'Sin asignar') AS agent,
                    t5.name AS status,
                    t6.name AS priority,
                    t7.name AS queue,
                    ISNULL(CAST(t1.rating AS VARCHAR), 'N/A') AS rating,
                    ISNULL(CAST(t8.name AS VARCHAR), 'N/A') AS ticket_type,
                    t9.name AS ticket_form,
                    CASE
                        WHEN LEN(t1.description) > 100 THEN LEFT(t1.description, 100) + '...'
                        ELSE t1.description
                    END AS truncated_description,
                    CASE
                        WHEN LEN(t1.title) > 50 THEN LEFT(t1.title, 50) + '...'
                        ELSE t1.title
                    END AS truncated_title,

                    -- CÁLCULO MIXTO DE HORAS SEGÚN PRIORIDAD
                    CASE
                        -- TICKETS URGENTES (10, 20): Horas naturales (24/7)
                        WHEN t1.priority_id IN (10, 20) THEN
                            CASE
                                WHEN t1.solved_on IS NOT NULL THEN
                                    DATEDIFF(HOUR, t1.created_on, t1.solved_on) +
                                    (DATEDIFF(MINUTE, t1.created_on, t1.solved_on) % 60) / 60.0
                                ELSE 0
                            END

                        -- TICKETS NORMALES (30, 40): Horas laborales (L-V 8:00-18:00)
                        WHEN t1.priority_id IN (30, 40) THEN
                            (
                                -- Horas del primer día
                                ISNULL(
                                    CASE
                                    WHEN DATEPART(WEEKDAY, t1.created_on) NOT IN (1, 2, 3, 4, 5)
                                        OR CAST(t1.created_on AS TIME) >= '18:00:00' THEN 0
                                    WHEN t1.solved_on IS NOT NULL
                                        AND CAST(t1.created_on AS DATE) = CAST(t1.solved_on AS DATE)
                                        AND CAST(t1.created_on AS TIME) < '08:00:00'
                                        AND CAST(t1.solved_on AS TIME) < '08:00:00' THEN 0
                                    WHEN t1.solved_on IS NOT NULL
                                        AND CAST(t1.created_on AS DATE) = CAST(t1.solved_on AS DATE) THEN
                                        DATEDIFF(MINUTE,
                                            CASE WHEN CAST(t1.created_on AS TIME) < '08:00:00' THEN CAST('08:00:00' AS TIME) ELSE CAST(t1.created_on AS TIME) END,
                                            CASE WHEN CAST(t1.solved_on AS TIME) > '18:00:00' THEN CAST('18:00:00' AS TIME) ELSE CAST(t1.solved_on AS TIME) END
                                        ) / 60.0
                                    ELSE
                                        DATEDIFF(MINUTE,
                                            CASE WHEN CAST(t1.created_on AS TIME) < '08:00:00' THEN CAST('08:00:00' AS TIME) ELSE CAST(t1.created_on AS TIME) END,
                                            CAST('18:00:00' AS TIME)
                                        ) / 60.0
                                END
                                , 0) +
                                -- Horas de días intermedios completos
                                ISNULL(
                                    CASE
                                        WHEN t1.solved_on IS NULL THEN 0
                                        ELSE (
                                            SELECT COUNT(*) * 10.0
                                            FROM (
                                                SELECT DATEADD(DAY, number, CAST(t1.created_on AS DATE)) AS dia
                                                FROM master.dbo.spt_values
                                                WHERE type = 'P'
                                                    AND number BETWEEN 1 AND DATEDIFF(DAY, CAST(t1.created_on AS DATE), CAST(t1.solved_on AS DATE)) - 1
                                            ) AS dias
                                            WHERE DATEPART(WEEKDAY, dia) BETWEEN 1 AND 5
                                        )
                                    END
                                , 0) +
                                -- Horas del último día
                                ISNULL(
                                    CASE
                                        WHEN t1.solved_on IS NULL
                                                OR DATEPART(WEEKDAY, t1.solved_on) NOT IN (1, 2, 3, 4, 5)
                                                OR CAST(t1.solved_on AS TIME) <= '08:00:00'
                                                OR CAST(t1.created_on AS DATE) = CAST(t1.solved_on AS DATE)
                                        THEN 0
                                        ELSE DATEDIFF(MINUTE,
                                                CAST('08:00:00' AS TIME),
                                                CASE
                                                    WHEN CAST(t1.solved_on AS TIME) > '18:00:00' THEN CAST('18:00:00' AS TIME)
                                                    ELSE CAST(t1.solved_on AS TIME)
                                                END
                                                ) / 60.0
                                    END,
                                0)
                            )
                        ELSE 0
                    END AS hours_to_resolve,

                    -- DESGLOSE PARA TICKETS NORMALES (solo para referencia)
                    CASE
                        WHEN t1.priority_id IN (30, 40) THEN
                            -- Horas del primer día
                            CASE
                                WHEN DATEPART(WEEKDAY, t1.created_on) NOT IN (1, 2, 3, 4, 5)
                                    OR CAST(t1.created_on AS TIME) >= '18:00:00' THEN 0
                                WHEN t1.solved_on IS NOT NULL
                                    AND CAST(t1.created_on AS DATE) = CAST(t1.solved_on AS DATE)
                                    AND CAST(t1.created_on AS TIME) < '08:00:00'
                                    AND CAST(t1.solved_on AS TIME) < '08:00:00' THEN 0
                                WHEN t1.solved_on IS NOT NULL
                                    AND CAST(t1.created_on AS DATE) = CAST(t1.solved_on AS DATE) THEN
                                    DATEDIFF(MINUTE,
                                        CASE WHEN CAST(t1.created_on AS TIME) < '08:00:00' THEN CAST('08:00:00' AS TIME) ELSE CAST(t1.created_on AS TIME) END,
                                        CASE WHEN CAST(t1.solved_on AS TIME) > '18:00:00' THEN CAST('18:00:00' AS TIME) ELSE CAST(t1.solved_on AS TIME) END
                                    ) / 60.0
                                ELSE
                                    DATEDIFF(MINUTE,
                                        CASE WHEN CAST(t1.created_on AS TIME) < '08:00:00' THEN CAST('08:00:00' AS TIME) ELSE CAST(t1.created_on AS TIME) END,
                                        CAST('18:00:00' AS TIME)
                                    ) / 60.0
                            END
                        ELSE NULL -- No aplica para tickets urgentes
                    END AS first_day_hours,

                    CASE
                        WHEN t1.priority_id IN (30, 40) THEN
                            -- Horas de días intermedios completos
                            CASE
                                WHEN t1.solved_on IS NULL THEN 0
                                ELSE (
                                    SELECT COUNT(*) * 10.0
                                    FROM (
                                        SELECT DATEADD(DAY, number, CAST(t1.created_on AS DATE)) AS dia
                                        FROM master.dbo.spt_values
                                        WHERE type = 'P'
                                        AND number BETWEEN 1 AND DATEDIFF(DAY, CAST(t1.created_on AS DATE), CAST(t1.solved_on AS DATE)) - 1
                                    ) AS dias
                                    WHERE DATEPART(WEEKDAY, dia) BETWEEN 1 AND 5
                                )
                            END
                        ELSE NULL -- No aplica para tickets urgentes
                    END AS middle_full_days_hours,

                    CASE
                        WHEN t1.priority_id IN (30, 40) THEN
                            -- Horas del último día
                            CASE
                                WHEN DATEPART(WEEKDAY, t1.solved_on) NOT IN (1, 2, 3, 4, 5) OR CAST(t1.solved_on AS TIME) <= '08:00:00' OR CAST(t1.created_on AS DATE) = CAST(t1.solved_on AS DATE) THEN 0
                                ELSE DATEDIFF(MINUTE,
                                        CAST('08:00:00' AS TIME),
                                        CASE WHEN CAST(t1.solved_on AS TIME) > '18:00:00' THEN CAST('18:00:00' AS TIME) ELSE CAST(t1.solved_on AS TIME) END
                                    ) / 60.0
                            END
                        ELSE NULL -- No aplica para tickets urgentes
                    END AS last_day_hours,

                    -- INDICADOR DEL TIPO DE CÁLCULO USADO
                    CASE
                        WHEN t1.priority_id IN (10, 20) THEN 'Horas Naturales (24/7)'
                        WHEN t1.priority_id IN (30, 40) THEN 'Horas Laborales (L-V 8-18)'
                        ELSE 'Sin Cálculo'
                    END AS calculation_type,

                    -- Día de la semana de creación en español
                    CASE DATEPART(WEEKDAY, t1.created_on)
                        WHEN 1 THEN 'Lunes'
                        WHEN 2 THEN 'Martes'
                        WHEN 3 THEN 'Miércoles'
                        WHEN 4 THEN 'Jueves'
                        WHEN 5 THEN 'Viernes'
                        WHEN 6 THEN 'Sábado'
                        WHEN 7 THEN 'Domingo'
                    END AS dia_semana_creacion,

                    -- Día de la semana de solución en español (si solved_on no es NULL)
                    CASE
                        WHEN t1.solved_on IS NULL THEN NULL
                        ELSE
                            CASE DATEPART(WEEKDAY, t1.solved_on)
                                WHEN 1 THEN 'Lunes'
                                WHEN 2 THEN 'Martes'
                                WHEN 3 THEN 'Miércoles'
                                WHEN 4 THEN 'Jueves'
                                WHEN 5 THEN 'Viernes'
                                WHEN 6 THEN 'Sábado'
                                WHEN 7 THEN 'Domingo'
                            END
                    END AS dia_semana_solucion
                FROM
                    [TG].[dbo].[mojo_tickets] t1
                    LEFT JOIN [TG].[dbo].[mojo_companies] t2 ON t1.company_id = t2.id_mojo
                    LEFT JOIN [TG].[dbo].[mojo_users] t3 ON t1.user_id = t3.id_mojo
                    LEFT JOIN [TG].[dbo].[mojo_users] t4 ON t1.assigned_to_id = t4.id_mojo
                    LEFT JOIN [TG].[dbo].[mojo_ticket_status] t5 ON t1.status_id = t5.id_mojo
                    LEFT JOIN [TG].[dbo].[mojo_ticket_priority] t6 ON t1.priority_id = t6.id_mojo
                    LEFT JOIN [TG].[dbo].[mojo_ticket_queue] t7 ON t1.ticket_queue_id = t7.id_mojo
                    LEFT JOIN [TG].[dbo].[mojo_ticket_types] t8 ON t1.ticket_type_id = t8.id_mojo
                    LEFT JOIN [TG].[dbo].[mojo_ticket_forms] t9 ON t1.ticket_form_id = t9.id_mojo
                WHERE
                    t1.created_on BETWEEN @fechaInicio AND @fechaFin
                    AND t1.ticket_form_id = {$ticket_form}
                ORDER BY
                    t1.created_on DESC;
                ;";

        return $this->sql->select($query);
    }

    function update_ticket($ticket) : bool {
        $solicitante = (isset($ticket['custom_field_solicitante']) ? $ticket['custom_field_solicitante'] : null);
        $problema = (isset($ticket['custom_field_problema']) ? $ticket['custom_field_problema'] : null);

        $ticket_forms = [
            'custom_field_area_o_departamento' => [51598, 72404, 72397, 72712, 73137],
            'custom_field_estacion' => [57328, 15139, 54072],
            'custom_field_estacin' => [63319, 51646]
        ];

        $requesting_department = null;

        foreach ($ticket_forms as $field => $ids) {
            if (in_array($ticket['ticket_form_id'], $ids)) {
                $requesting_department = $this->getCustomField($field, $ticket);
                break;
            }
        }

        // Convertir fechas de UTC a hora local de México
        $solved_on = $this->convertUtcToLocal($ticket['solved_on']);
        $rated_on = $this->convertUtcToLocal($ticket['rated_on']);
        $assigned_on = $this->convertUtcToLocal($ticket['assigned_on']);
        $created_on = $this->convertUtcToLocal($ticket['created_on']);
        $updated_on = $this->convertUtcToLocal($ticket['updated_on']);
        $status_changed_on = $this->convertUtcToLocal($ticket['status_changed_on']);
        $first_assigned_on = $this->convertUtcToLocal($ticket['first_assigned_on']);
        $due_on = $this->convertUtcToLocal($ticket['due_on']);
        $scheduled_on = $this->convertUtcToLocal($ticket['scheduled_on']);
        $first_commented_on = $this->convertUtcToLocal($ticket['first_commented_on']);

        // Preparar valores para SQL
        $solved_on_sql = is_null($solved_on) ? "NULL" : "'{$solved_on}'";
        $rated_on_sql = is_null($rated_on) ? "NULL" : "'{$rated_on}'";
        $assigned_on_sql = is_null($assigned_on) ? "NULL" : "'{$assigned_on}'";
        $created_on_sql = is_null($created_on) ? "NULL" : "'{$created_on}'";
        $updated_on_sql = is_null($updated_on) ? "NULL" : "'{$updated_on}'";
        $status_changed_on_sql = is_null($status_changed_on) ? "NULL" : "'{$status_changed_on}'";
        $first_assigned_on_sql = is_null($first_assigned_on) ? "NULL" : "'{$first_assigned_on}'";
        $due_on_sql = is_null($due_on) ? "NULL" : "'{$due_on}'";
        $scheduled_on_sql = is_null($scheduled_on) ? "NULL" : "'{$scheduled_on}'";
        $first_commented_on_sql = is_null($first_commented_on) ? "NULL" : "'{$first_commented_on}'";

        $query = "
        DECLARE @id_mojo INT = {$ticket['id']};
        DECLARE @created_on DATETIME = $created_on_sql;
        DECLARE @updated_on DATETIME = $updated_on_sql;
        DECLARE @status_changed_on DATETIME = $status_changed_on_sql;
        DECLARE @first_assigned_on DATETIME = $first_assigned_on_sql;
        DECLARE @due_on DATETIME = $due_on_sql;
        DECLARE @scheduled_on DATETIME = $scheduled_on_sql;
        DECLARE @first_commented_on DATETIME = $first_commented_on_sql;
        DECLARE @rated_on DATETIME = $rated_on_sql;
        DECLARE @solved_on DATETIME = $solved_on_sql;
        DECLARE @assigned_on DATETIME = $assigned_on_sql;
        
        IF EXISTS (SELECT 1 FROM [TG].[dbo].[mojo_tickets] WHERE id_mojo = @id_mojo)
            BEGIN
                UPDATE [TG].[dbo].[mojo_tickets]
                SET
                    title = '{$ticket['title']}',
                    description = '{$ticket['description']}',
                    user_id = {$ticket['user_id']},
                    company_id = {$ticket['company_id']},
                    assigned_to_id = '{$ticket['assigned_to_id']}',
                    status_id = {$ticket['status_id']},
                    priority_id = {$ticket['priority_id']},
                    ticket_queue_id = {$ticket['ticket_queue_id']},
                    rating = '{$ticket['rating']}',
                    rated_on = @rated_on,
                    created_on = @created_on,
                    updated_on = @updated_on,
                    status_changed_on = @status_changed_on,
                    solved_on = @solved_on,
                    assigned_on = @assigned_on,
                    ticket_type_id = '{$ticket['ticket_type_id']}',
                    first_assigned_on = @first_assigned_on,
                    due_on = @due_on,
                    scheduled_on = @scheduled_on,
                    is_attention_required = '{$ticket['is_attention_required']}',
                    ticket_form_id = {$ticket['ticket_form_id']},
                    resolution_id = 1,
                    first_commented_on = @first_commented_on,
                    rating_comment = '{$ticket['rating_comment']}',
                    requesting_department = '{$requesting_department}',
                    applicants_name = '{$solicitante}',
                    problem = '{$problema}'
                WHERE id_mojo = @id_mojo
            END
        ELSE
            BEGIN
                INSERT INTO [TG].[dbo].[mojo_tickets] (
                    id_mojo,
                    title,
                    description,
                    user_id,
                    company_id,
                    assigned_to_id,
                    status_id,
                    priority_id,
                    ticket_queue_id,
                    rating,
                    rated_on,
                    created_on,
                    updated_on,
                    status_changed_on,
                    solved_on,
                    assigned_on,
                    created_from,
                    ticket_type_id,
                    cc,
                    legacy_id,
                    first_assigned_on,
                    due_on,
                    scheduled_on,
                    is_attention_required,
                    ticket_form_id,
                    resolution_id,
                    first_commented_on,
                    rating_comment,
                    requesting_department,
                    applicants_name,
                    problem
                ) VALUES (@id_mojo, '{$ticket['title']}', '{$ticket['description']}', {$ticket['user_id']}, {$ticket['company_id']}, '{$ticket['assigned_to_id']}', {$ticket['status_id']}, {$ticket['priority_id']}, {$ticket['ticket_queue_id']}, '{$ticket['rating']}', @rated_on, @created_on, @updated_on, @status_changed_on, @solved_on, @assigned_on, null, '{$ticket['ticket_type_id']}', null, null, @first_assigned_on, @due_on, @scheduled_on, 
                '{$ticket['is_attention_required']}', {$ticket['ticket_form_id']}, 1, $first_commented_on_sql, '{$ticket['rating_comment']}', '{$requesting_department}', '{$solicitante}', '{$problema}')
            END
        ";

        return $this->sql->query($query);
    }

    /**
     * Convierte una fecha UTC del API de Mojo Helpdesk a hora local de México
     * en un formato compatible con SQL Server datetime
     */
    private function convertUtcToLocal($fecha_utc_str) {
        if (empty($fecha_utc_str) || $fecha_utc_str === 'None' || is_null($fecha_utc_str)) {
            return null;
        }
        
        try {
            // Crear timezone objects
            $utc_tz = new DateTimeZone('UTC');
            $local_tz = new DateTimeZone('America/Mexico_City');
            
            // Parsear la fecha
            if (strpos($fecha_utc_str, 'T') !== false) {
                // Eliminar la Z y los milisegundos si existen
                $fecha_utc_str = explode('.', $fecha_utc_str)[0];
                $fecha_utc_str = str_replace('Z', '', $fecha_utc_str);
                $fecha_utc = DateTime::createFromFormat('Y-m-d\TH:i:s', $fecha_utc_str, $utc_tz);
            } else {
                $fecha_utc = DateTime::createFromFormat('Y-m-d H:i:s', $fecha_utc_str, $utc_tz);
            }
            
            if ($fecha_utc === false) {
                throw new Exception("No se pudo parsear la fecha: $fecha_utc_str");
            }
            
            // Convertir a hora local
            $fecha_local = $fecha_utc->setTimezone($local_tz);
            
            // Retornar en formato ISO 8601 para SQL Server (sin información de timezone)
            return $fecha_local->format('Y-m-d\TH:i:s');
            
        } catch (Exception $e) {
            error_log("Error convirtiendo fecha $fecha_utc_str: " . $e->getMessage());
            return null;
        }
    }

    function getCustomField($field, $ticket) {
        return isset($ticket[$field]) ? $ticket[$field] : null;
    }

    function update_group($group) : bool {
        $query = "
        IF EXISTS (SELECT 1 FROM [TG].[dbo].[mojo_companies] WHERE id_mojo = ?)
        BEGIN
            UPDATE [TG].[dbo].[mojo_companies]
            SET name = ?, primary_contact_id = ?, billing_contact_id = ?, support_level_id = ?, support_status_id = ?, support_start_date = ?, support_end_date = ?, support_info_url = ?, address = ?, address2 = ?, city = ?, state = ?, zip = ?, country = ?, website_url = ?, notes = ?
            WHERE id_mojo = ?
        END
        ELSE
        BEGIN
            INSERT INTO [TG].[dbo].[mojo_companies] (id_mojo,name,primary_contact_id,billing_contact_id,support_level_id,support_status_id,support_start_date,support_end_date,support_info_url,address,address2,city,state,zip,country,website_url,notes) 
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) 
        END";
        $params = [$group['id'],$group['name'],$group['primary_contact_id'],$group['billing_contact_id'],$group['support_level_id'],$group['support_status_id'],$group['support_start_date'],$group['support_end_date'],$group['support_info_url'],$group['address'],$group['address2'],$group['city'],$group['state'],$group['zip'],$group['country'],$group['website_url'],$group['notes'],$group['id'],
            $group['id'],$group['name'],$group['primary_contact_id'],$group['billing_contact_id'],$group['support_level_id'],$group['support_status_id'],$group['support_start_date'],$group['support_end_date'],$group['support_info_url'],$group['address'],$group['address2'],$group['city'],$group['state'],$group['zip'],$group['country'],$group['website_url'],$group['notes']];

        return $this->sql->query($query, $params);
    }

    function update_queue($queue) : bool {
        $query = "
        IF EXISTS (SELECT 1 FROM [TG].[dbo].[mojo_ticket_queue] WHERE id_mojo = ?)
        BEGIN
            UPDATE [TG].[dbo].[mojo_ticket_queue]
            SET name = ?, email_alias = ?, email_forward = ?, email_ticket_form_id = ?
            WHERE id_mojo = ?
        END
        ELSE
        BEGIN
            INSERT INTO [TG].[dbo].[mojo_ticket_queue]  (id_mojo,name,email_alias,email_forward,email_ticket_form_id)
            VALUES (?,?,?,?,?)
        END
        ";

        $params = [$queue['id'],$queue['name'],$queue['email_alias'],$queue['email_forward'],$queue['email_ticket_form_id'],$queue['id'],
            $queue['id'],$queue['name'],$queue['email_alias'],$queue['email_forward'],$queue['email_ticket_form_id']];

        return $this->sql->query($query, $params);
    }

    function update_user($user) : bool {
        $query = "
        IF EXISTS (SELECT 1 FROM [TG].[dbo].[mojo_users] WHERE id_mojo = ?)
            BEGIN
                UPDATE [TG].[dbo].[mojo_users]
                SET
                    role_id=?,company_id=?,is_active=?,first_name=?,middle_name=?,last_name=?,email=?,work_phone=?,cell_phone=?,home_phone=?,fax_phone=?,im_screen_name=?,user_notes=?,helpdesk_notes=?,last_login_on=?,access_key=?,created_on=?,updated_on=?,timer_auto_start=?,time_zone=?,do_not_email_ever=?,address=?,last_active_on=?,is_on_vacation=?,is_asset_manager=?,picture_url=?
                WHERE id_mojo = ?
            END
            ELSE
            BEGIN
                INSERT INTO [TG].[dbo].[mojo_users] (
                    id_mojo,role_id,company_id,is_active,first_name,middle_name,last_name,email,work_phone,cell_phone,home_phone,fax_phone,im_screen_name,user_notes,helpdesk_notes,last_login_on,access_key,created_on,updated_on,timer_auto_start,time_zone,do_not_email_ever,address,last_active_on,is_on_vacation,is_asset_manager,picture_url
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            END
        ";
        $params = [$user['id'],$user['role_id'],$user['company_id'],$user['is_active'],$user['first_name'],$user['middle_name'],$user['last_name'],$user['email'],$user['work_phone'],$user['cell_phone'],$user['home_phone'],$user['fax_phone'],$user['im_screen_name'],$user['user_notes'],$user['helpdesk_notes'],$user['last_login_on'],$user['access_key'],$user['created_on'],$user['updated_on'],$user['timer_auto_start'],$user['time_zone'],$user['do_not_email_ever'],$user['address'],$user['last_active_on'],$user['is_on_vacation'],$user['is_asset_manager'],$user['picture_url'],$user['id'],
            $user['id'],$user['role_id'],$user['company_id'],$user['is_active'],$user['first_name'],$user['middle_name'],$user['last_name'],$user['email'],$user['work_phone'],$user['cell_phone'],$user['home_phone'],$user['fax_phone'],$user['im_screen_name'],$user['user_notes'],$user['helpdesk_notes'],$user['last_login_on'],$user['access_key'],$user['created_on'],$user['updated_on'],$user['timer_auto_start'],$user['time_zone'],$user['do_not_email_ever'],$user['address'],$user['last_active_on'],$user['is_on_vacation'],$user['is_asset_manager'],$user['picture_url']];

        return $this->sql->query($query, $params);
    }

    function update_tag($tag) : bool {
        $query = "
        IF EXISTS (SELECT 1 FROM [TG].[dbo].[mojo_ticket_tags] WHERE id_mojo = ?)
        BEGIN
            UPDATE [TG].[dbo].[mojo_ticket_tags]
            SET label = ?, color = ?
            WHERE id_mojo = ?
        END
        ELSE
        BEGIN
            INSERT INTO [TG].[dbo].[mojo_ticket_tags]  (id_mojo,label,color)
            VALUES (?,?,?)
        END
        ";
        $params = [$tag['id'],$tag['label'],$tag['color'],$tag['id'],
            $tag['id'],$tag['label'],$tag['color']];
        return $this->sql->query($query, $params);
    }

    function update_form($form) : bool {
        $query = "
        IF EXISTS (SELECT 1 FROM [TG].[dbo].[mojo_ticket_forms] WHERE id_mojo = ?)
        BEGIN
            UPDATE [TG].[dbo].[mojo_ticket_forms]
            SET name=?,is_default=?,is_enabled=?,description=?,is_visible_for_end_user=?,show_cc=?,show_attach_files=?,is_visible_for_non_logged_in_user=?
            WHERE id_mojo = ?
        END
        ELSE
        BEGIN
            INSERT INTO [TG].[dbo].[mojo_ticket_forms]  (id_mojo,name,is_default,is_enabled,description,is_visible_for_end_user,show_cc,show_attach_files,is_visible_for_non_logged_in_user)
            VALUES (?,?,?,?,?,?,?,?,?)
        END
        ";
        $params = [$form['id'],$form['name'],$form['is_default'],$form['is_enabled'],$form['description'],$form['is_visible_for_end_user'],$form['show_cc'],$form['show_attach_files'],$form['is_visible_for_non_logged_in_user'],$form['id'],
            $form['id'],$form['name'],$form['is_default'],$form['is_enabled'],$form['description'],$form['is_visible_for_end_user'],$form['show_cc'],$form['show_attach_files'],$form['is_visible_for_non_logged_in_user']];

        return $this->sql->query($query, $params);
    }

    function update_type($type) : bool {
        $query = "
        IF EXISTS (SELECT 1 FROM [TG].[dbo].[mojo_ticket_types] WHERE id_mojo = ?)
        BEGIN
            UPDATE [TG].[dbo].[mojo_ticket_types]
            SET name=?
            WHERE id_mojo = ?
        END
        ELSE
        BEGIN
            INSERT INTO [TG].[dbo].[mojo_ticket_types]  (id_mojo,name)
            VALUES (?,?)
        END
        ";
        $params = [$type['id'],$type['name'],$type['id'],
            $type['id'],$type['name']];
        return $this->sql->query($query, $params);
    }

    function delete_ticket($ticket_id) {
        return $this->sql->delete("DELETE FROM [TG].[dbo].[mojo_tickets] WHERE id_mojo = ?;", [$ticket_id]);
    }

    function get_tickets_forms() : array | false {
        $query = "SELECT * FROM [TG].[dbo].[mojo_ticket_forms] WHERE is_enabled = 1 AND is_visible_for_end_user = 1;";
        return $this->sql->select($query);
    }

    function get_tickets_by_form_and_month($from, $until,$form_id) : array | false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final
        
        -- Ajustar la fecha final para ser el último día del mes anterior
        SET @EndDate = DATEADD(DAY, -DAY(@EndDate), @EndDate);
        
        -- Crear una tabla temporal para los meses entre la fecha inicial y la fecha final
        WITH MonthsInRange AS (
            SELECT DATEADD(MONTH, DATEDIFF(MONTH, 0, @StartDate), 0) AS first_day_of_month
            UNION ALL
            SELECT DATEADD(MONTH, 1, first_day_of_month)
            FROM MonthsInRange
            WHERE first_day_of_month < @EndDate
        ),
        -- Crear combinaciones de estados y meses
        StatusMonths AS (
            SELECT 
                ts.id_mojo,
                ts.name,
                YEAR(mr.first_day_of_month) AS year,
                MONTH(mr.first_day_of_month) AS month,
                CONVERT(DATE, mr.first_day_of_month) AS start_of_month,
                EOMONTH(mr.first_day_of_month) AS end_of_month
            FROM 
                [TG].[dbo].[mojo_ticket_status] ts
            CROSS JOIN 
                MonthsInRange mr
        )
        -- Seleccionar y unir con el conteo de tickets
        SELECT 
            sm.id_mojo,
            sm.name,
            sm.year,
            sm.month,
            sm.start_of_month,
            sm.end_of_month,
            ISNULL(t.ticket_count, 0) AS ticket_count
        FROM 
            StatusMonths sm
        LEFT JOIN (
            SELECT 
                status_id,
                YEAR(created_on) AS year,
                MONTH(created_on) AS month,
                COUNT(*) AS ticket_count
            FROM 
                [TG].[dbo].[mojo_tickets]
            WHERE 
                ticket_form_id = {$form_id}
                AND created_on >= @StartDate -- Fecha inicial
                AND created_on <= DATEADD(MONTH, 1, @EndDate) -- Fecha final (sin incluir el primer día del siguiente mes)
            GROUP BY 
                status_id,
                YEAR(created_on),
                MONTH(created_on)
        ) t ON sm.id_mojo = t.status_id AND sm.year = t.year AND sm.month = t.month
        ORDER BY 
            sm.year, 
            sm.month, 
            sm.id_mojo;
        ";

        return $this->sql->select($query);
    }

    function get_tickets_by_form_and_week($from, $until, $form_id) : array | false {

        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial Nuevo
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final

        -- Crear una tabla temporal para las semanas entre la fecha inicial y la fecha final
        WITH WeeksInRange AS (
            SELECT DATEADD(WEEK, DATEDIFF(WEEK, 0, @StartDate), 0) AS first_day_of_week
            UNION ALL
            SELECT DATEADD(WEEK, 1, first_day_of_week)
            FROM WeeksInRange
            WHERE first_day_of_week < @EndDate
        ),
        -- Crear combinaciones de estados y semanas
        StatusWeeks AS (
            SELECT
                ts.id_mojo,
                ts.name,
                YEAR(wr.first_day_of_week) AS year,
                DATEPART(ISO_WEEK, wr.first_day_of_week) AS week_number,
                wr.first_day_of_week AS initial_date,
                DATEADD(DAY, 6, wr.first_day_of_week) AS final_date
            FROM
                [TG].[dbo].[mojo_ticket_status] ts
            CROSS JOIN
                WeeksInRange wr
        )
        -- Seleccionar y unir con el conteo de tickets
        SELECT
            sw.id_mojo,
            sw.name,
            sw.year,
            sw.week_number,
            sw.initial_date,
            sw.final_date,
            ISNULL(t.ticket_count, 0) AS ticket_count
        FROM
            StatusWeeks sw
        LEFT JOIN (
            SELECT
                status_id,
                YEAR(created_on) AS year,
                DATEPART(ISO_WEEK, created_on) AS week_number,
                COUNT(*) AS ticket_count
            FROM
                [TG].[dbo].[mojo_tickets]
            WHERE
                ticket_form_id = {$form_id}
                AND created_on >= @StartDate -- Fecha inicial
                AND created_on < DATEADD(WEEK, 1, @EndDate) -- Fecha final (sin incluir el primer día de la semana siguiente)
            GROUP BY
                status_id,
                YEAR(created_on),
                DATEPART(ISO_WEEK, created_on)
        ) t ON sw.id_mojo = t.status_id AND sw.year = t.year AND sw.week_number = t.week_number
        ORDER BY
            sw.year,
            sw.week_number,
            sw.id_mojo;
        ";

        return $this->sql->select($query);
    }

    function get_tickets_by_form_and_year($from, $until, $form_id) : array | false {
        $query = "
            DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
            DECLARE @EndDate DATE = '{$until}'; -- Fecha final

            -- Crear una tabla temporal para los años entre la fecha inicial y la fecha final
            WITH YearsInRange AS (
              SELECT DATEADD(YEAR, DATEDIFF(YEAR, 0, @StartDate), 0) AS first_day_of_year
              UNION ALL
              SELECT DATEADD(YEAR, 1, first_day_of_year)
              FROM YearsInRange
              WHERE DATEPART(YEAR, DATEADD(YEAR, 1, first_day_of_year)) <= DATEPART(YEAR, @EndDate)
            ),
            -- Crear combinaciones de estados y años
            StatusYears AS (
                SELECT
                    ts.id_mojo,
                    ts.name,
                    YEAR(yr.first_day_of_year) AS year
                FROM
                    [TG].[dbo].[mojo_ticket_status] ts
                CROSS JOIN
                    YearsInRange yr
            )
            -- Seleccionar y unir con el conteo de tickets
            SELECT
                sy.id_mojo,
                sy.name,
                sy.year,
                ISNULL(t.ticket_count, 0) AS ticket_count
            FROM
                StatusYears sy
            LEFT JOIN (
                SELECT
                    status_id,
                    YEAR(created_on) AS year,
                    COUNT(*) AS ticket_count
                FROM
                    [TG].[dbo].[mojo_tickets]
                WHERE
                    ticket_form_id = {$form_id}
                    AND created_on >= @StartDate -- Fecha inicial
                    AND created_on <= DATEADD(YEAR, 1, @EndDate) -- Fecha final (excluyendo el 1 de enero de 2025)
                GROUP BY
                    status_id,
                    YEAR(created_on)
            ) t ON sy.id_mojo = t.status_id AND sy.year = t.year
            ORDER BY
                sy.year,
                sy.id_mojo;
        ";
        return $this->sql->select($query);
    }

    function get_urgent_tickets($from, $until, $ticket_form) : array|false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final

        ;WITH WeekData AS (
            SELECT
                DATEPART(ISOWK, created_on) AS week_number,
                COUNT(*) AS tickets_qty,
                SUM(DATEDIFF(MINUTE, created_on, COALESCE(solved_on, @EndDate)) / 60.0) AS total_hours_elapsed,
                AVG(DATEDIFF(MINUTE, created_on, COALESCE(solved_on, @EndDate)) / 60.0) AS avg_hours_elapsed
            FROM
                [TG].[dbo].[mojo_tickets]
            WHERE
                created_on >= @StartDate AND
                created_on <= @EndDate AND
                priority_id IN (10,20) AND
                ticket_form_id = {$ticket_form}
            GROUP BY
                DATEPART(ISOWK, created_on)
        ),
        WeekDates AS (
            SELECT
                DISTINCT DATEPART(ISOWK, created_on) AS week_number,
                MIN(DATEADD(DAY, 1 - DATEPART(WEEKDAY, created_on), created_on)) OVER (PARTITION BY DATEPART(ISOWK, created_on)) AS week_start_date,
                MAX(DATEADD(DAY, 7 - DATEPART(WEEKDAY, created_on), created_on)) OVER (PARTITION BY DATEPART(ISOWK, created_on)) AS week_end_date
            FROM
                [TG].[dbo].[mojo_tickets]
            WHERE
                created_on >= @StartDate AND
                created_on <= @EndDate AND
                priority_id IN (10,20) AND
                ticket_form_id = {$ticket_form}
        )
        SELECT
            w.week_number,
            w.tickets_qty,
            w.total_hours_elapsed,
            w.avg_hours_elapsed,
            d.week_start_date,
            d.week_end_date
        FROM
            WeekData w
        JOIN
            WeekDates d ON w.week_number = d.week_number
        ORDER BY
            w.week_number;
        ";


        return $this->sql->select($query);
    }

    function get_urgent_tickets_months($from, $until, $ticket_form) : array|false {
        $query = "
            DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
            DECLARE @EndDate DATE = '{$until}'; -- Fecha final

            ;WITH MonthData AS (
                SELECT
                    DATEPART(YEAR, created_on) AS year,
                    DATEPART(MONTH, created_on) AS month,
                    COUNT(*) AS tickets_qty,
                    SUM(DATEDIFF(MINUTE, created_on, COALESCE(solved_on, @EndDate)) / 60.0) AS total_hours_elapsed,
                    AVG(DATEDIFF(MINUTE, created_on, COALESCE(solved_on, @EndDate)) / 60.0) AS avg_hours_elapsed
                FROM
                    [TG].[dbo].[mojo_tickets]
                WHERE
                    created_on >= @StartDate AND
                    created_on <= @EndDate AND
                    priority_id IN (10,20) AND
                    ticket_form_id = {$ticket_form}
                GROUP BY
                    DATEPART(YEAR, created_on),
                    DATEPART(MONTH, created_on)
            ),
            MonthDates AS (
                SELECT
                    DISTINCT DATEPART(YEAR, created_on) AS year,
                    DATEPART(MONTH, created_on) AS month,
                    DATEFROMPARTS(DATEPART(YEAR, created_on), DATEPART(MONTH, created_on), 1) AS month_start_date,
                    EOMONTH(DATEFROMPARTS(DATEPART(YEAR, created_on), DATEPART(MONTH, created_on), 1)) AS month_end_date
                FROM
                    [TG].[dbo].[mojo_tickets]
                WHERE
                    created_on >= @StartDate AND
                    created_on <= @EndDate AND
                    priority_id IN (10, 20) AND
                    ticket_form_id = {$ticket_form}
            )
            SELECT
                m.year,
                m.month,
                FORMAT(DATEFROMPARTS(m.year, m.month, 1), 'MMMM', 'es-ES') AS month_name, -- Nombre del mes
                m.tickets_qty,
                m.total_hours_elapsed,
                m.avg_hours_elapsed,
                d.month_start_date,
                d.month_end_date
            FROM
                MonthData m
            JOIN
                MonthDates d ON m.year = d.year AND m.month = d.month
            ORDER BY
                m.year, m.month;
        ";
        return $this->sql->select($query);
    }

    function get_urgent_tickets_years($from, $until, $ticket_form) : array|false {
        $query = "
            DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
            DECLARE @EndDate DATE = '{$until}'; -- Fecha final

            ;WITH YearData AS (
                SELECT
                    DATEPART(YEAR, created_on) AS year,
                    COUNT(*) AS tickets_qty,
                    SUM(DATEDIFF(MINUTE, created_on, COALESCE(solved_on, @EndDate)) / 60.0) AS total_hours_elapsed,
                    AVG(DATEDIFF(MINUTE, created_on, COALESCE(solved_on, @EndDate)) / 60.0) AS avg_hours_elapsed
                FROM
                    [TG].[dbo].[mojo_tickets]
                WHERE
                    created_on >= @StartDate AND
                    created_on <= @EndDate AND
                    priority_id IN (10,20) AND
                    ticket_form_id = {$ticket_form}
                GROUP BY
                    DATEPART(YEAR, created_on)
            ),
            YearDates AS (
                SELECT
                    DISTINCT DATEPART(YEAR, created_on) AS year,
                    DATEFROMPARTS(DATEPART(YEAR, created_on), 1, 1) AS year_start_date,
                    DATEFROMPARTS(DATEPART(YEAR, created_on), 12, 31) AS year_end_date
                FROM
                    [TG].[dbo].[mojo_tickets]
                WHERE
                    created_on >= @StartDate AND
                    created_on <= @EndDate AND
                    priority_id IN (10, 20) AND
                    ticket_form_id = {$ticket_form}
            )
            SELECT
                y.year,
                y.tickets_qty,
                y.total_hours_elapsed,
                y.avg_hours_elapsed,
                d.year_start_date,
                d.year_end_date
            FROM
                YearData y
            JOIN
                YearDates d ON y.year = d.year
            ORDER BY
                y.year;
        ";

        return $this->sql->select($query);
    }

    function get_normal_tickets($from, $until, $ticket_form) : array|false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final

        ;WITH BusinessHoursCalculation AS (
            SELECT
                *,
                -- Si solved_on es NULL, usar @EndDate
                COALESCE(solved_on, @EndDate) AS effective_solved_on,

                -- Ajustar created_on al horario laboral (08:00 AM si es antes)
                CASE
                    WHEN DATEPART(HOUR, created_on) < 8 THEN
                        DATEADD(HOUR, 8, CAST(CAST(created_on AS DATE) AS DATETIME))
                    WHEN DATEPART(HOUR, created_on) >= 18 THEN
                        DATEADD(HOUR, 8, DATEADD(DAY, 1, CAST(CAST(created_on AS DATE) AS DATETIME)))
                    ELSE created_on
                END AS adjusted_created_on,

                -- Ajustar solved_on al horario laboral (06:00 PM si es después)
                CASE
                    WHEN DATEPART(HOUR, COALESCE(solved_on, @EndDate)) < 8 THEN
                        DATEADD(HOUR, 18, DATEADD(DAY, -1, CAST(CAST(COALESCE(solved_on, @EndDate) AS DATE) AS DATETIME)))
                    WHEN DATEPART(HOUR, COALESCE(solved_on, @EndDate)) >= 18 THEN
                        DATEADD(HOUR, 18, CAST(CAST(COALESCE(solved_on, @EndDate) AS DATE) AS DATETIME))
                    ELSE COALESCE(solved_on, @EndDate)
                END AS adjusted_solved_on
            FROM [TG].[dbo].[mojo_tickets]
            WHERE
                created_on >= @StartDate AND
                created_on <= @EndDate AND
                priority_id IN (30, 40) AND
                ticket_form_id = {$ticket_form}
        ),
        BusinessHoursOnly AS (
            SELECT
                *,
                -- Calcular horas laborales
                CASE
                    WHEN adjusted_created_on >= adjusted_solved_on THEN 0
                    ELSE (
                        -- Total de días laborales completos entre las fechas
                        (CASE
                            WHEN DATEDIFF(DAY, CAST(adjusted_created_on AS DATE), CAST(adjusted_solved_on AS DATE)) <= 1 THEN 0
                            ELSE (
                                SELECT COUNT(*)
                                FROM (
                                    SELECT DATEADD(DAY, number, CAST(adjusted_created_on AS DATE)) AS check_date
                                    FROM master.dbo.spt_values
                                    WHERE type = 'P'
                                    AND number BETWEEN 1 AND DATEDIFF(DAY, CAST(adjusted_created_on AS DATE), CAST(adjusted_solved_on AS DATE)) - 1
                                ) dates
                                WHERE DATEPART(WEEKDAY, check_date) NOT IN (6, 7) -- Excluir sábados (6) y domingos (7)
                            )
                        END * 10.0) + -- 10 horas por día laboral completo (8 AM a 6 PM)

                        -- Horas del día de inicio
                        CASE
                            WHEN CAST(adjusted_created_on AS DATE) = CAST(adjusted_solved_on AS DATE) THEN
                                -- Mismo día
                                CASE
                                    WHEN DATEPART(WEEKDAY, adjusted_created_on) IN (6, 7) THEN 0
                                    ELSE DATEDIFF(MINUTE, adjusted_created_on, adjusted_solved_on) / 60.0
                                END
                            ELSE
                                -- Diferentes días - horas restantes del día de inicio
                                CASE
                                    WHEN DATEPART(WEEKDAY, adjusted_created_on) IN (6, 7) THEN 0
                                    ELSE DATEDIFF(MINUTE, adjusted_created_on,
                                        DATEADD(HOUR, 18, CAST(CAST(adjusted_created_on AS DATE) AS DATETIME))) / 60.0
                                END
                        END +

                        -- Horas del día de fin (solo si es diferente al día de inicio)
                        CASE
                            WHEN CAST(adjusted_created_on AS DATE) = CAST(adjusted_solved_on AS DATE) THEN 0
                            ELSE
                                CASE
                                    WHEN DATEPART(WEEKDAY, adjusted_solved_on) IN (6, 7) THEN 0
                                    ELSE DATEDIFF(MINUTE,
                                        DATEADD(HOUR, 8, CAST(CAST(adjusted_solved_on AS DATE) AS DATETIME)),
                                        adjusted_solved_on) / 60.0
                                END
                        END
                    )
                END AS business_hours_elapsed
            FROM BusinessHoursCalculation
        ),
        WeekData AS (
            SELECT
                DATEPART(ISOWK, created_on) AS week_number,
                COUNT(*) AS tickets_qty,
                SUM(business_hours_elapsed) AS total_hours_elapsed,
                AVG(business_hours_elapsed) AS avg_hours_elapsed
            FROM BusinessHoursOnly
            GROUP BY DATEPART(ISOWK, created_on)
        ),
        WeekDates AS (
            SELECT
                DISTINCT DATEPART(ISOWK, created_on) AS week_number,
                MIN(DATEADD(DAY, 1 - DATEPART(WEEKDAY, created_on), created_on)) OVER (PARTITION BY DATEPART(ISOWK, created_on)) AS week_start_date,
                MAX(DATEADD(DAY, 7 - DATEPART(WEEKDAY, created_on), created_on)) OVER (PARTITION BY DATEPART(ISOWK, created_on)) AS week_end_date
            FROM [TG].[dbo].[mojo_tickets]
            WHERE
                created_on >= @StartDate AND
                created_on <= @EndDate AND
                priority_id IN (30,40) AND
                ticket_form_id = {$ticket_form}
        )
        SELECT
            w.week_number,
            w.tickets_qty,
            w.total_hours_elapsed,
            w.avg_hours_elapsed,
            d.week_start_date,
            d.week_end_date
        FROM WeekData w
        JOIN WeekDates d ON w.week_number = d.week_number
        ORDER BY w.week_number;
        ";
        return $this->sql->select($query);
    }


    function get_normal_tickets_month($from, $until, $ticket_form) : array|false {
        $query = "
            DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
            DECLARE @EndDate DATE = '{$until}'; -- Fecha final

            ;WITH BusinessHoursCalculation AS (
                SELECT
                    *,
                    -- Si solved_on es NULL, usar @EndDate
                    COALESCE(solved_on, @EndDate) AS effective_solved_on,

                    -- Ajustar created_on al horario laboral (08:00 AM si es antes)
                    CASE
                        WHEN DATEPART(HOUR, created_on) < 8 THEN
                            DATEADD(HOUR, 8, CAST(CAST(created_on AS DATE) AS DATETIME))
                        WHEN DATEPART(HOUR, created_on) >= 18 THEN
                            DATEADD(HOUR, 8, DATEADD(DAY, 1, CAST(CAST(created_on AS DATE) AS DATETIME)))
                        ELSE created_on
                    END AS adjusted_created_on,

                    -- Ajustar solved_on al horario laboral (06:00 PM si es después)
                    CASE
                        WHEN DATEPART(HOUR, COALESCE(solved_on, @EndDate)) < 8 THEN
                            DATEADD(HOUR, 18, DATEADD(DAY, -1, CAST(CAST(COALESCE(solved_on, @EndDate) AS DATE) AS DATETIME)))
                        WHEN DATEPART(HOUR, COALESCE(solved_on, @EndDate)) >= 18 THEN
                            DATEADD(HOUR, 18, CAST(CAST(COALESCE(solved_on, @EndDate) AS DATE) AS DATETIME))
                        ELSE COALESCE(solved_on, @EndDate)
                    END AS adjusted_solved_on
                FROM [TG].[dbo].[mojo_tickets]
                WHERE
                    created_on >= @StartDate AND
                    created_on <= @EndDate AND
                    priority_id IN (30, 40) AND
                    ticket_form_id = {$ticket_form}
            ),
            BusinessHoursOnly AS (
                SELECT
                    *,
                    -- Calcular horas laborales
                    CASE
                        WHEN adjusted_created_on >= adjusted_solved_on THEN 0
                        ELSE (
                            -- Total de días laborales completos entre las fechas
                            (CASE
                                WHEN DATEDIFF(DAY, CAST(adjusted_created_on AS DATE), CAST(adjusted_solved_on AS DATE)) <= 1 THEN 0
                                ELSE (
                                    SELECT COUNT(*)
                                    FROM (
                                        SELECT DATEADD(DAY, number, CAST(adjusted_created_on AS DATE)) AS check_date
                                        FROM master.dbo.spt_values
                                        WHERE type = 'P'
                                        AND number BETWEEN 1 AND DATEDIFF(DAY, CAST(adjusted_created_on AS DATE), CAST(adjusted_solved_on AS DATE)) - 1
                                    ) dates
                                    WHERE DATEPART(WEEKDAY, check_date) NOT IN (6, 7) -- Excluir sábados (6) y domingos (7)
                                )
                            END * 10.0) + -- 10 horas por día laboral completo (8 AM a 6 PM)

                            -- Horas del día de inicio
                            CASE
                                WHEN CAST(adjusted_created_on AS DATE) = CAST(adjusted_solved_on AS DATE) THEN
                                    -- Mismo día
                                    CASE
                                        WHEN DATEPART(WEEKDAY, adjusted_created_on) IN (6, 7) THEN 0
                                        ELSE DATEDIFF(MINUTE, adjusted_created_on, adjusted_solved_on) / 60.0
                                    END
                                ELSE
                                    -- Diferentes días - horas restantes del día de inicio
                                    CASE
                                        WHEN DATEPART(WEEKDAY, adjusted_created_on) IN (6, 7) THEN 0
                                        ELSE DATEDIFF(MINUTE, adjusted_created_on,
                                            DATEADD(HOUR, 18, CAST(CAST(adjusted_created_on AS DATE) AS DATETIME))) / 60.0
                                    END
                            END +
                            -- Horas del día de fin (solo si es diferente al día de inicio)
                            CASE
                                WHEN CAST(adjusted_created_on AS DATE) = CAST(adjusted_solved_on AS DATE) THEN 0
                                ELSE
                                    CASE
                                        WHEN DATEPART(WEEKDAY, adjusted_solved_on) IN (6, 7) THEN 0
                                        ELSE DATEDIFF(MINUTE,
                                            DATEADD(HOUR, 8, CAST(CAST(adjusted_solved_on AS DATE) AS DATETIME)),
                                            adjusted_solved_on) / 60.0
                                    END
                            END
                        )
                    END AS business_hours_elapsed
                FROM BusinessHoursCalculation
            ),
            MonthData AS (
                SELECT
                    DATEPART(YEAR, created_on) AS year,
                    DATEPART(MONTH, created_on) AS month,
                    COUNT(*) AS tickets_qty,
                    SUM(business_hours_elapsed) AS total_hours_elapsed,
                    AVG(business_hours_elapsed) AS avg_hours_elapsed
                FROM BusinessHoursOnly
                GROUP BY
                    DATEPART(YEAR, created_on),
                    DATEPART(MONTH, created_on)
            ),
            MonthDates AS (
                SELECT
                    DISTINCT DATEPART(YEAR, created_on) AS year,
                    DATEPART(MONTH, created_on) AS month,
                    DATEFROMPARTS(DATEPART(YEAR, created_on), DATEPART(MONTH, created_on), 1) AS month_start_date,
                    EOMONTH(DATEFROMPARTS(DATEPART(YEAR, created_on), DATEPART(MONTH, created_on), 1)) AS month_end_date
                FROM [TG].[dbo].[mojo_tickets]
                WHERE
                    created_on >= @StartDate AND
                    created_on <= @EndDate AND
                    priority_id IN (30,40) AND
                    ticket_form_id = {$ticket_form}
            )
            SELECT
                m.year,
                m.month,
                FORMAT(DATEFROMPARTS(m.year, m.month, 1), 'MMMM', 'es-ES') AS month_name, -- Nombre del mes
                m.tickets_qty,
                m.total_hours_elapsed,
                m.avg_hours_elapsed,
                d.month_start_date,
                d.month_end_date
            FROM MonthData m
            JOIN MonthDates d ON m.year = d.year AND m.month = d.month
            ORDER BY m.year, m.month;
        ";
        return $this->sql->select($query);
    }

    function get_normal_tickets_years($from, $until, $ticket_form) : array|false {
        $query = "
            DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
            DECLARE @EndDate DATE = '{$until}'; -- Fecha final

            ;WITH BusinessHoursCalculation AS (
                SELECT
                    *,
                    -- Si solved_on es NULL, usar @EndDate
                    COALESCE(solved_on, @EndDate) AS effective_solved_on,

                    -- Ajustar created_on al horario laboral (08:00 AM si es antes)
                    CASE
                        WHEN DATEPART(HOUR, created_on) < 8 THEN
                            DATEADD(HOUR, 8, CAST(CAST(created_on AS DATE) AS DATETIME))
                        WHEN DATEPART(HOUR, created_on) >= 18 THEN
                            DATEADD(HOUR, 8, DATEADD(DAY, 1, CAST(CAST(created_on AS DATE) AS DATETIME)))
                        ELSE created_on
                    END AS adjusted_created_on,
                    -- Ajustar solved_on al horario laboral (06:00 PM si es después)
                    CASE
                        WHEN DATEPART(HOUR, COALESCE(solved_on, @EndDate)) < 8 THEN
                            DATEADD(HOUR, 18, DATEADD(DAY, -1, CAST(CAST(COALESCE(solved_on, @EndDate) AS DATE) AS DATETIME)))
                        WHEN DATEPART(HOUR, COALESCE(solved_on, @EndDate)) >= 18 THEN
                            DATEADD(HOUR, 18, CAST(CAST(COALESCE(solved_on, @EndDate) AS DATE) AS DATETIME))
                        ELSE COALESCE(solved_on, @EndDate)
                    END AS adjusted_solved_on
                FROM [TG].[dbo].[mojo_tickets]
                WHERE
                    created_on >= @StartDate AND
                    created_on <= @EndDate AND
                    priority_id IN (30, 40) AND
                    ticket_form_id = {$ticket_form}
            ),
            BusinessHoursOnly AS (
                SELECT
                    *,
                    -- Calcular horas laborales
                    CASE
                        WHEN adjusted_created_on >= adjusted_solved_on THEN 0
                        ELSE (
                            -- Total de días laborales completos entre las fechas
                            (CASE
                                WHEN DATEDIFF(DAY, CAST(adjusted_created_on AS DATE), CAST(adjusted_solved_on AS DATE)) <= 1 THEN 0
                                ELSE (
                                    SELECT COUNT(*)
                                    FROM (
                                        SELECT DATEADD(DAY, number, CAST(adjusted_created_on AS DATE)) AS check_date
                                        FROM master.dbo.spt_values
                                        WHERE type = 'P'
                                        AND number BETWEEN 1 AND DATEDIFF(DAY, CAST(adjusted_created_on AS DATE), CAST(adjusted_solved_on AS DATE)) - 1
                                    ) dates
                                    WHERE DATEPART(WEEKDAY, check_date) NOT IN (6, 7) -- Excluir sábados (6) y domingos (7)
                                )
                            END * 10.0) + -- 10 horas por día laboral completo (8 AM a 6 PM)

                            -- Horas del día de inicio
                            CASE
                                WHEN CAST(adjusted_created_on AS DATE) = CAST(adjusted_solved_on AS DATE) THEN
                                    -- Mismo día
                                    CASE
                                        WHEN DATEPART(WEEKDAY, adjusted_created_on) IN (6, 7) THEN 0
                                        ELSE DATEDIFF(MINUTE, adjusted_created_on, adjusted_solved_on) / 60.0
                                    END
                                ELSE
                                    -- Diferentes días - horas restantes del día de inicio
                                    CASE
                                        WHEN DATEPART(WEEKDAY, adjusted_created_on) IN (6, 7) THEN 0
                                        ELSE DATEDIFF(MINUTE, adjusted_created_on,
                                            DATEADD(HOUR, 18, CAST(CAST(adjusted_created_on AS DATE) AS DATETIME))) / 60.0
                                    END
                            END +

                            -- Horas del día de fin (solo si es diferente al día de inicio)
                            CASE
                                WHEN CAST(adjusted_created_on AS DATE) = CAST(adjusted_solved_on AS DATE) THEN 0
                                ELSE
                                    CASE
                                        WHEN DATEPART(WEEKDAY, adjusted_solved_on) IN (6, 7) THEN 0
                                        ELSE DATEDIFF(MINUTE,
                                            DATEADD(HOUR, 8, CAST(CAST(adjusted_solved_on AS DATE) AS DATETIME)),
                                            adjusted_solved_on) / 60.0
                                    END
                            END
                        )
                    END AS business_hours_elapsed
                FROM BusinessHoursCalculation
            ),
            YearData AS (
                SELECT
                    DATEPART(YEAR, created_on) AS year,
                    COUNT(*) AS tickets_qty,
                    SUM(business_hours_elapsed) AS total_hours_elapsed,
                    AVG(business_hours_elapsed) AS avg_hours_elapsed
                FROM BusinessHoursOnly
                GROUP BY
                    DATEPART(YEAR, created_on)
            ),
            YearDates AS (
                SELECT
                    DISTINCT DATEPART(YEAR, created_on) AS year,
                    DATEFROMPARTS(DATEPART(YEAR, created_on), 1, 1) AS year_start_date,
                    DATEFROMPARTS(DATEPART(YEAR, created_on), 12, 31) AS year_end_date
                FROM [TG].[dbo].[mojo_tickets]
                WHERE
                    created_on >= @StartDate AND
                    created_on <= @EndDate AND
                    priority_id IN (30,40) AND
                    ticket_form_id = {$ticket_form}
            )
            SELECT
                y.year,
                y.tickets_qty,
                y.total_hours_elapsed,
                y.avg_hours_elapsed,
                d.year_start_date,
                d.year_end_date
            FROM YearData y
            JOIN YearDates d ON y.year = d.year
            ORDER BY y.year;
        ";

        return $this->sql->select($query);
    }

    function get_status_open_tickets($from, $until, $ticket_form) : array|false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final

       ;WITH WeekData AS (
            SELECT
                DATEPART(ISOWK, created_on) AS week_number,
                COUNT(*) AS tickets_qty,
                SUM(DATEDIFF(MINUTE, created_on, solved_on) / 60.0) AS total_hours_elapsed,
                AVG(DATEDIFF(MINUTE, created_on, solved_on) / 60.0) AS avg_hours_elapsed
            FROM
                [TG].[dbo].[mojo_tickets]
            WHERE
                created_on >= @StartDate AND
                created_on <= @EndDate AND
                status_id  IN (10,20,30,40) AND
                 ticket_form_id = {$ticket_form}
            GROUP BY
                DATEPART(ISOWK, created_on)
        ),
        WeekDates AS (
            SELECT
                DISTINCT DATEPART(ISOWK, created_on) AS week_number,
                MIN(DATEADD(DAY, 1 - DATEPART(WEEKDAY, created_on), created_on)) OVER (PARTITION BY DATEPART(ISOWK, created_on)) AS week_start_date,
                MAX(DATEADD(DAY, 7 - DATEPART(WEEKDAY, created_on), created_on)) OVER (PARTITION BY DATEPART(ISOWK, created_on)) AS week_end_date
            FROM
                [TG].[dbo].[mojo_tickets]
            WHERE
                created_on >= @StartDate AND
                created_on <= @EndDate AND
                status_id  IN (10,20,30,40) AND
                 ticket_form_id = {$ticket_form}
        )
        SELECT
            w.week_number,
            w.tickets_qty,
            w.total_hours_elapsed,
            w.avg_hours_elapsed,
            d.week_start_date,
            d.week_end_date
        FROM
            WeekData w
        JOIN
            WeekDates d ON w.week_number = d.week_number
        ORDER BY
            w.week_number
        ";

        return $this->sql->select($query);
    }
    function get_status_open_months($from, $until, $ticket_form) : array|false {
        $query = "
            DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
            DECLARE @EndDate DATE = '{$until}'; -- Fecha final

            ;WITH MonthData AS (
                SELECT 
                    DATEPART(YEAR, created_on) AS year,
                    DATEPART(MONTH, created_on) AS month,
                    COUNT(*) AS tickets_qty,
                    SUM(DATEDIFF(MINUTE, created_on, solved_on) / 60.0) AS total_hours_elapsed,
                    AVG(DATEDIFF(MINUTE, created_on, solved_on) / 60.0) AS avg_hours_elapsed
                FROM 
                    [TG].[dbo].[mojo_tickets] 
                WHERE 
                    created_on >= @StartDate AND 
                    created_on <= @EndDate AND 
                    status_id IN (10,20,30,40) AND 
                    ticket_form_id = {$ticket_form}
                GROUP BY 
                    DATEPART(YEAR, created_on),
                    DATEPART(MONTH, created_on)
            ),
            MonthDates AS (
                SELECT 
                    DISTINCT DATEPART(YEAR, created_on) AS year,
                    DATEPART(MONTH, created_on) AS month,
                    DATEFROMPARTS(DATEPART(YEAR, created_on), DATEPART(MONTH, created_on), 1) AS month_start_date,
                    EOMONTH(DATEFROMPARTS(DATEPART(YEAR, created_on), DATEPART(MONTH, created_on), 1)) AS month_end_date
                FROM 
                    [TG].[dbo].[mojo_tickets] 
                WHERE 
                    created_on >= @StartDate AND 
                    created_on <= @EndDate AND 
                    status_id IN (10,20,30,40) AND 
                    ticket_form_id = {$ticket_form}
            )
            SELECT 
                m.year,
                m.month,
                FORMAT(DATEFROMPARTS(m.year, m.month, 1), 'MMMM', 'es-ES') AS month_name, -- Nombre del mes
                m.tickets_qty,
                m.total_hours_elapsed,
                m.avg_hours_elapsed,
                d.month_start_date,
                d.month_end_date
            FROM 
                MonthData m
            JOIN 
                MonthDates d ON m.year = d.year AND m.month = d.month
            ORDER BY 
                m.year, m.month;
        ";
        return $this->sql->select($query);
    }
    function get_support_types($from, $until, $ticket_form) : array | false {
        $query = "
            DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
            DECLARE @EndDate DATE = '{$until}'; -- Fecha final
            SELECT
                problem, COUNT(*) total
            FROM
                [TG].[dbo].[mojo_tickets]
            WHERE
                created_on >= @StartDate AND created_on <= @EndDate AND
                ticket_form_id = {$ticket_form}
            GROUP BY problem
            ORDER BY total DESC;
        ";

        return $this->sql->select($query);
    }

    function get_ticket_users($from, $until, $ticket_form) : array|false {
        $query = "
            DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
            DECLARE @EndDate DATE = '{$until}'; -- Fecha final
            
            SELECT
                t1.user_id, 
                (ISNULL(t2.first_name, '') + ' ' + ISNULL(t2.last_name, '')) AS full_name, 
                COUNT(*) AS total
            FROM
                [TG].[dbo].[mojo_tickets] t1
                LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.user_id = t2.id_mojo
            WHERE
                t1.created_on >= @StartDate AND t1.created_on <= @EndDate AND
                t1.ticket_form_id = {$ticket_form}
            GROUP BY 
                t1.user_id, 
                t2.first_name, 
                t2.last_name
            ORDER BY 
                total DESC;
        ";
        return $this->sql->select($query);
    }

    function get_ticket_groups($from, $until, $ticket_form) : array|false {
        $query = "
            DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
            DECLARE @EndDate DATE = '{$until}'; -- Fecha final
                        
            SELECT
                t1.company_id AS user_id, 
                t2.name AS full_name, 
                COUNT(*) AS total
            FROM
                [TG].[dbo].[mojo_tickets] t1
                LEFT JOIN [TG].[dbo].[mojo_companies] t2 ON t1.company_id = t2.id_mojo
            WHERE
                t1.created_on >= @StartDate AND t1.created_on <= @EndDate AND
                t1.ticket_form_id = {$ticket_form}
            GROUP BY 
                t1.company_id, 
                t2.name
            ORDER BY 
                total DESC;
        ";
        return $this->sql->select($query);
    }

    function get_ticket_departments($from, $until, $ticket_form) : array|false {
        $query = "
            DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
            DECLARE @EndDate DATE = '{$until}'; -- Fecha final
                        
            SELECT
                t1.requesting_department AS user_id, 
                t1.requesting_department AS full_name, 
                COUNT(*) AS total
            FROM
                [TG].[dbo].[mojo_tickets] t1
                LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.user_id = t2.id_mojo
            WHERE
                t1.created_on >= @StartDate AND t1.created_on <= @EndDate AND
                t1.ticket_form_id = {$ticket_form}
            GROUP BY 
                t1.requesting_department
            ORDER BY 
                total DESC;
        ";
        return $this->sql->select($query);
    }

    function get_agents_tickets_total($from, $until, $ticket_form) : array | false {

        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final

        ;WITH BusinessHoursCalc AS (
            SELECT
                t1.assigned_to_id,
                t1.priority_id,
                t1.status_id,
                t1.created_on,
                COALESCE(t1.solved_on, @EndDate) AS end_date,
                DATEPART(ISOWK, t1.created_on) AS week_iso,

                -- Calcular horas según la prioridad del ticket
                CASE
                    -- TICKETS URGENTES (10, 20): Horas naturales (24/7)
                    WHEN t1.priority_id IN (10, 20) THEN
                        DATEDIFF(HOUR, t1.created_on, COALESCE(t1.solved_on, @EndDate)) +
                        (DATEDIFF(MINUTE, t1.created_on, COALESCE(t1.solved_on, @EndDate)) % 60) / 60.0

                    -- TICKETS NORMALES (30, 40): Horas laborales (L-V 8:00-18:00)
                    WHEN t1.priority_id IN (30, 40) AND COALESCE(t1.solved_on, @EndDate) IS NOT NULL THEN
                        -- Días completos entre fechas (excluyendo primer y último día)
                        (
                            SELECT COUNT(*) * 10.0
                            FROM (
                                SELECT DATEADD(DAY, number + 1, CAST(t1.created_on AS DATE)) AS day_date
                                FROM master.dbo.spt_values
                                WHERE type = 'P'
                                AND number + 1 < DATEDIFF(DAY, CAST(t1.created_on AS DATE), CAST(COALESCE(t1.solved_on, @EndDate) AS DATE))
                                AND DATEPART(WEEKDAY, DATEADD(DAY, number + 1, CAST(t1.created_on AS DATE))) NOT IN (6, 7)
                            ) AS intermediate_days
                        ) +
                        -- Horas del primer día
                        CASE
                            WHEN DATEPART(WEEKDAY, t1.created_on) NOT IN (6, 7) THEN
                                CASE
                                    WHEN CAST(t1.created_on AS DATE) = CAST(COALESCE(t1.solved_on, @EndDate) AS DATE) THEN
                                        -- Mismo día
                                        CASE
                                            WHEN CAST(t1.created_on AS TIME) >= '18:00:00' OR CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) <= '08:00:00' THEN 0
                                            ELSE
                                                DATEDIFF(MINUTE,
                                                    CASE WHEN CAST(t1.created_on AS TIME) < '08:00:00' THEN '08:00:00' ELSE CAST(t1.created_on AS TIME) END,
                                                    CASE WHEN CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) > '18:00:00' THEN '18:00:00' ELSE CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) END
                                                ) / 60.0
                                        END
                                    ELSE
                                        -- Diferente día - calcular desde created_on hasta 18:00
                                        CASE
                                            WHEN CAST(t1.created_on AS TIME) >= '18:00:00' THEN 0
                                            ELSE
                                                DATEDIFF(MINUTE,
                                                    CASE WHEN CAST(t1.created_on AS TIME) < '08:00:00' THEN '08:00:00' ELSE CAST(t1.created_on AS TIME) END,
                                                    '18:00:00'
                                                ) / 60.0
                                        END
                                END
                            ELSE 0
                        END +
                        -- Horas del último día (solo si es diferente al primero)
                        CASE
                            WHEN CAST(t1.created_on AS DATE) != CAST(COALESCE(t1.solved_on, @EndDate) AS DATE)
                            AND DATEPART(WEEKDAY, COALESCE(t1.solved_on, @EndDate)) NOT IN (6, 7) THEN
                                CASE
                                    WHEN CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) <= '08:00:00' THEN 0
                                    ELSE
                                        DATEDIFF(MINUTE,
                                            '08:00:00',
                                            CASE WHEN CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) > '18:00:00' THEN '18:00:00' ELSE CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) END
                                        ) / 60.0
                                END
                            ELSE 0
                        END
                    ELSE 0
                END AS business_hours,

                t2.first_name,
                t2.last_name
            FROM [TG].[dbo].[mojo_tickets] t1
            LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
            WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
            AND t1.ticket_form_id = {$ticket_form}
        ),
        Tickets AS (
            SELECT
                assigned_to_id,
                CONCAT('Sem. ', week_iso) AS WeekISO,
                COUNT(*) AS TotalTickets,
                SUM(CASE WHEN status_id IN (50, 60) THEN 1 ELSE 0 END) AS ResolvedTickets,
                SUM(CASE WHEN status_id IN (10, 20, 30, 40) THEN 1 ELSE 0 END) AS PendingTickets,
                SUM(CASE WHEN priority_id IN (10, 20) THEN 1 ELSE 0 END) AS UrgentTickets,
                SUM(CASE WHEN priority_id IN (30, 40) THEN 1 ELSE 0 END) AS NormalTickets,

                -- Total horas para tickets urgentes (HORAS NATURALES 24/7)
                SUM(CASE WHEN priority_id IN (10, 20) THEN business_hours ELSE 0 END) AS TotalHoursUrgentTickets,

                -- Total horas para tickets normales (HORAS LABORALES L-V 8-18)
                SUM(CASE WHEN priority_id IN (30, 40) THEN business_hours ELSE 0 END) AS TotalHoursNormalTickets,

                -- Total horas para todos los tickets (mixto según prioridad)
                SUM(business_hours) AS TotalHoursDifference,

                CONCAT(
                    MAX(CASE WHEN first_name IS NULL OR first_name = '' THEN 'No asignado' ELSE first_name END),
                    ' ',
                    MAX(CASE WHEN last_name IS NULL OR last_name = '' THEN '' ELSE last_name END)
                ) AS AssignedUserName,
                DATEADD(wk, DATEDIFF(wk, 0, MIN(created_on)), 0) AS StartOfWeek,
                DATEADD(wk, DATEDIFF(wk, 0, MIN(created_on)), 6) AS EndOfWeek
            FROM BusinessHoursCalc
            GROUP BY assigned_to_id, week_iso
        )
        SELECT
            assigned_to_id,
            WeekISO,
            TotalTickets,
            ResolvedTickets, -- Tickets resueltos
            PendingTickets, -- Tickets pendientes
            UrgentTickets, -- Tickets urgentes (HORAS NATURALES)
            NormalTickets, -- Tickets normales (HORAS LABORALES)
            TotalHoursUrgentTickets, -- Horas naturales tickets urgentes
            TotalHoursNormalTickets, -- Horas laborales tickets normales
            TotalHoursDifference, -- Total mixto según prioridad
            CASE
                WHEN TotalTickets > 0
                THEN TotalHoursDifference / TotalTickets
                ELSE 0
            END AS AverageHoursDifference, -- Promedio mixto según prioridad
            AssignedUserName,
            StartOfWeek,
            EndOfWeek
        FROM Tickets
        ORDER BY assigned_to_id, WeekISO;
        ";

        return $this->sql->select($query);
    }

    function get_agents_tickets_total_month($from, $until, $ticket_form) : array | false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final

        ;WITH BusinessHoursCalc AS (
            SELECT
                COALESCE(t1.assigned_to_id, 0) AS assigned_to_id,
                t1.priority_id,
                t1.status_id,
                t1.created_on,
                COALESCE(t1.solved_on, @EndDate) AS end_date,
                YEAR(t1.created_on) AS Year,
                MONTH(t1.created_on) AS Month,
                CONCAT(YEAR(t1.created_on), '-', RIGHT('0' + CAST(MONTH(t1.created_on) AS VARCHAR(2)), 2)) AS MonthName,

                -- Calcular horas según la prioridad del ticket
                CASE
                    -- TICKETS URGENTES (10, 20): Horas naturales (24/7)
                    WHEN t1.priority_id IN (10, 20) THEN
                        DATEDIFF(HOUR, t1.created_on, COALESCE(t1.solved_on, @EndDate)) +
                        (DATEDIFF(MINUTE, t1.created_on, COALESCE(t1.solved_on, @EndDate)) % 60) / 60.0

                    -- TICKETS NORMALES (30, 40): Horas laborales (L-V 8:00-18:00)
                    WHEN t1.priority_id IN (30, 40) AND COALESCE(t1.solved_on, @EndDate) IS NOT NULL THEN
                        -- Días completos entre fechas (excluyendo primer y último día)
                        (
                            SELECT COUNT(*) * 10.0
                            FROM (
                                SELECT DATEADD(DAY, number + 1, CAST(t1.created_on AS DATE)) AS day_date
                                FROM master.dbo.spt_values
                                WHERE type = 'P'
                                AND number + 1 < DATEDIFF(DAY, CAST(t1.created_on AS DATE), CAST(COALESCE(t1.solved_on, @EndDate) AS DATE))
                                AND DATEPART(WEEKDAY, DATEADD(DAY, number + 1, CAST(t1.created_on AS DATE))) NOT IN (6, 7)
                            ) AS intermediate_days
                        ) +
                        -- Horas del primer día
                        CASE
                            WHEN DATEPART(WEEKDAY, t1.created_on) NOT IN (6, 7) THEN
                                CASE
                                    WHEN CAST(t1.created_on AS DATE) = CAST(COALESCE(t1.solved_on, @EndDate) AS DATE) THEN
                                        -- Mismo día
                                        CASE
                                            WHEN CAST(t1.created_on AS TIME) >= '18:00:00' OR CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) <= '08:00:00' THEN 0
                                            ELSE
                                                DATEDIFF(MINUTE,
                                                    CASE WHEN CAST(t1.created_on AS TIME) < '08:00:00' THEN '08:00:00' ELSE CAST(t1.created_on AS TIME) END,
                                                    CASE WHEN CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) > '18:00:00' THEN '18:00:00' ELSE CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) END
                                                ) / 60.0
                                        END
                                    ELSE
                                        -- Diferente día - calcular desde created_on hasta 18:00
                                        CASE
                                            WHEN CAST(t1.created_on AS TIME) >= '18:00:00' THEN 0
                                            ELSE
                                                DATEDIFF(MINUTE,
                                                    CASE WHEN CAST(t1.created_on AS TIME) < '08:00:00' THEN '08:00:00' ELSE CAST(t1.created_on AS TIME) END,
                                                    '18:00:00'
                                                ) / 60.0
                                        END
                                END
                            ELSE 0
                        END +
                        -- Horas del último día (solo si es diferente al primero)
                        CASE
                            WHEN CAST(t1.created_on AS DATE) != CAST(COALESCE(t1.solved_on, @EndDate) AS DATE)
                            AND DATEPART(WEEKDAY, COALESCE(t1.solved_on, @EndDate)) NOT IN (6, 7) THEN
                                CASE
                                    WHEN CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) <= '08:00:00' THEN 0
                                    ELSE
                                        DATEDIFF(MINUTE,
                                            '08:00:00',
                                            CASE WHEN CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) > '18:00:00' THEN '18:00:00' ELSE CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) END
                                        ) / 60.0
                                END
                            ELSE 0
                        END
                    ELSE 0
                END AS business_hours,

                t2.first_name,
                t2.last_name,
                DATEFROMPARTS(YEAR(t1.created_on), MONTH(t1.created_on), 1) AS StartOfMonth,
                EOMONTH(t1.created_on) AS EndOfMonth
            FROM [TG].[dbo].[mojo_tickets] t1
            LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
            WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
            AND t1.ticket_form_id = {$ticket_form}
        ),
        Tickets AS (
            SELECT
                assigned_to_id,
                Year,
                Month,
                MonthName,
                COUNT(*) AS TotalTickets,
                SUM(CASE WHEN status_id IN (50, 60) THEN 1 ELSE 0 END) AS ResolvedTickets, -- Tickets resueltos
                SUM(CASE WHEN status_id IN (10, 20, 30, 40) THEN 1 ELSE 0 END) AS PendingTickets, -- Tickets pendientes
                SUM(CASE WHEN priority_id IN (10, 20) THEN 1 ELSE 0 END) AS UrgentTickets, -- Tickets urgentes
                SUM(CASE WHEN priority_id IN (30, 40) THEN 1 ELSE 0 END) AS NormalTickets, -- Tickets normales

                -- Total horas para tickets urgentes (HORAS NATURALES 24/7)
                SUM(CASE WHEN priority_id IN (10, 20) THEN business_hours ELSE 0 END) AS TotalHoursUrgentTickets,

                -- Total horas para tickets normales (HORAS LABORALES L-V 8-18)
                SUM(CASE WHEN priority_id IN (30, 40) THEN business_hours ELSE 0 END) AS TotalHoursNormalTickets,

                -- Total horas para todos los tickets (mixto según prioridad)
                SUM(business_hours) AS TotalHoursDifference,

                CONCAT(
                    MAX(CASE WHEN first_name IS NULL OR first_name = '' THEN 'No asignado' ELSE first_name END),
                    ' ',
                    MAX(CASE WHEN last_name IS NULL OR last_name = '' THEN '' ELSE last_name END)
                ) AS AssignedUserName,
                MIN(StartOfMonth) AS StartOfMonth,
                MAX(EndOfMonth) AS EndOfMonth
            FROM BusinessHoursCalc
            GROUP BY
                assigned_to_id,
                Year,
                Month,
                MonthName
        )

        SELECT
            assigned_to_id,
            MonthName,
            SUM(TotalTickets) AS TotalTickets,
            SUM(ResolvedTickets) AS ResolvedTickets, -- Tickets resueltos
            SUM(PendingTickets) AS PendingTickets, -- Tickets pendientes
            SUM(UrgentTickets) AS UrgentTickets, -- Tickets urgentes (HORAS NATURALES)
            SUM(NormalTickets) AS NormalTickets, -- Tickets normales (HORAS LABORALES)
            SUM(TotalHoursUrgentTickets) AS TotalHoursUrgentTickets, -- Horas naturales tickets urgentes
            SUM(TotalHoursNormalTickets) AS TotalHoursNormalTickets, -- Horas laborales tickets normales
            SUM(TotalHoursDifference) AS TotalHoursDifference, -- Total mixto según prioridad
            CASE
                WHEN SUM(TotalTickets) > 0
                THEN SUM(TotalHoursDifference) / SUM(TotalTickets)
                ELSE 0
            END AS AverageHoursDifference, -- Promedio mixto según prioridad
            AssignedUserName,
            MIN(StartOfMonth) AS StartOfMonth,
            MAX(EndOfMonth) AS EndOfMonth
        FROM Tickets
        GROUP BY
            assigned_to_id,
            MonthName,
            AssignedUserName
        ORDER BY
            assigned_to_id,
            MonthName;
        ";
        return $this->sql->select($query);
    }

    function get_agents_tickets_total_year($from, $until, $ticket_form) : array | false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final

        ;WITH BusinessHoursCalc AS (
            SELECT
                COALESCE(t1.assigned_to_id, 0) AS assigned_to_id,
                t1.priority_id,
                t1.status_id,
                t1.created_on,
                COALESCE(t1.solved_on, @EndDate) AS end_date,
                YEAR(t1.created_on) AS Year,
                
                -- Calcular horas según la prioridad del ticket
                CASE
                    -- TICKETS URGENTES (10, 20): Horas naturales (24/7)
                    WHEN t1.priority_id IN (10, 20) THEN
                        DATEDIFF(HOUR, t1.created_on, COALESCE(t1.solved_on, @EndDate)) + 
                        (DATEDIFF(MINUTE, t1.created_on, COALESCE(t1.solved_on, @EndDate)) % 60) / 60.0
                    
                    -- TICKETS NORMALES (30, 40): Horas laborales (L-V 8:00-18:00)
                    WHEN t1.priority_id IN (30, 40) AND COALESCE(t1.solved_on, @EndDate) IS NOT NULL THEN
                        -- Días completos entre fechas (excluyendo primer y último día)
                        (
                            SELECT COUNT(*) * 10.0
                            FROM (
                                SELECT DATEADD(DAY, number + 1, CAST(t1.created_on AS DATE)) AS day_date
                                FROM master.dbo.spt_values
                                WHERE type = 'P'
                                AND number + 1 < DATEDIFF(DAY, CAST(t1.created_on AS DATE), CAST(COALESCE(t1.solved_on, @EndDate) AS DATE))
                                AND DATEPART(WEEKDAY, DATEADD(DAY, number + 1, CAST(t1.created_on AS DATE))) NOT IN (6, 7)
                            ) AS intermediate_days
                        ) +
                        -- Horas del primer día
                        CASE
                            WHEN DATEPART(WEEKDAY, t1.created_on) NOT IN (6, 7) THEN
                                CASE
                                    WHEN CAST(t1.created_on AS DATE) = CAST(COALESCE(t1.solved_on, @EndDate) AS DATE) THEN
                                        -- Mismo día
                                        CASE
                                            WHEN CAST(t1.created_on AS TIME) >= '18:00:00' OR CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) <= '08:00:00' THEN 0
                                            ELSE
                                                DATEDIFF(MINUTE,
                                                    CASE WHEN CAST(t1.created_on AS TIME) < '08:00:00' THEN '08:00:00' ELSE CAST(t1.created_on AS TIME) END,
                                                    CASE WHEN CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) > '18:00:00' THEN '18:00:00' ELSE CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) END
                                                ) / 60.0
                                        END
                                    ELSE
                                        -- Diferente día - calcular desde created_on hasta 18:00
                                        CASE
                                            WHEN CAST(t1.created_on AS TIME) >= '18:00:00' THEN 0
                                            ELSE
                                                DATEDIFF(MINUTE,
                                                    CASE WHEN CAST(t1.created_on AS TIME) < '08:00:00' THEN '08:00:00' ELSE CAST(t1.created_on AS TIME) END,
                                                    '18:00:00'
                                                ) / 60.0
                                        END
                                END
                            ELSE 0
                        END +
                        -- Horas del último día (solo si es diferente al primero)
                        CASE
                            WHEN CAST(t1.created_on AS DATE) != CAST(COALESCE(t1.solved_on, @EndDate) AS DATE)
                            AND DATEPART(WEEKDAY, COALESCE(t1.solved_on, @EndDate)) NOT IN (6, 7) THEN
                                CASE
                                    WHEN CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) <= '08:00:00' THEN 0
                                    ELSE
                                        DATEDIFF(MINUTE,
                                            '08:00:00',
                                            CASE WHEN CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) > '18:00:00' THEN '18:00:00' ELSE CAST(COALESCE(t1.solved_on, @EndDate) AS TIME) END
                                        ) / 60.0
                                END
                            ELSE 0
                        END
                    ELSE 0
                END AS business_hours,
                
                t2.first_name,
                t2.last_name
            FROM [TG].[dbo].[mojo_tickets] t1
            LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
            WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
            AND t1.ticket_form_id = 51598
        ),
        Tickets AS (
            SELECT
                assigned_to_id,
                Year,
                COUNT(*) AS TotalTickets,
                SUM(CASE WHEN status_id IN (50, 60) THEN 1 ELSE 0 END) AS ResolvedTickets, -- Tickets resueltos
                SUM(CASE WHEN status_id IN (10, 20, 30, 40) THEN 1 ELSE 0 END) AS PendingTickets, -- Tickets pendientes
                SUM(CASE WHEN priority_id IN (10, 20) THEN 1 ELSE 0 END) AS UrgentTickets, -- Tickets urgentes
                SUM(CASE WHEN priority_id IN (30, 40) THEN 1 ELSE 0 END) AS NormalTickets, -- Tickets normales

                -- Total horas para tickets urgentes (HORAS NATURALES 24/7)
                SUM(CASE WHEN priority_id IN (10, 20) THEN business_hours ELSE 0 END) AS TotalHoursUrgentTickets,

                -- Total horas para tickets normales (HORAS LABORALES L-V 8-18)
                SUM(CASE WHEN priority_id IN (30, 40) THEN business_hours ELSE 0 END) AS TotalHoursNormalTickets,

                -- Total horas para todos los tickets (mixto según prioridad)
                SUM(business_hours) AS TotalHoursDifference,

                CONCAT(
                    MAX(CASE WHEN first_name IS NULL OR first_name = '' THEN 'No asignado' ELSE first_name END),
                    ' ',
                    MAX(CASE WHEN last_name IS NULL OR last_name = '' THEN '' ELSE last_name END)
                ) AS AssignedUserName
            FROM BusinessHoursCalc
            GROUP BY
                assigned_to_id,
                Year
        )
        SELECT
            assigned_to_id,
            Year,
            SUM(TotalTickets) AS TotalTickets,
            SUM(ResolvedTickets) AS ResolvedTickets, -- Tickets resueltos
            SUM(PendingTickets) AS PendingTickets, -- Tickets pendientes
            SUM(UrgentTickets) AS UrgentTickets, -- Tickets urgentes (HORAS NATURALES)
            SUM(NormalTickets) AS NormalTickets, -- Tickets normales (HORAS LABORALES)
            SUM(TotalHoursUrgentTickets) AS TotalHoursUrgentTickets, -- Horas naturales tickets urgentes
            SUM(TotalHoursNormalTickets) AS TotalHoursNormalTickets, -- Horas laborales tickets normales
            SUM(TotalHoursDifference) AS TotalHoursDifference, -- Total mixto según prioridad
            CASE
                WHEN SUM(TotalTickets) > 0
                THEN SUM(TotalHoursDifference) / SUM(TotalTickets)
                ELSE 0
            END AS AverageHoursDifference, -- Promedio mixto según prioridad
            AssignedUserName
        FROM Tickets
        GROUP BY
            assigned_to_id,
            Year,
            AssignedUserName
        ORDER BY
            assigned_to_id,
            Year;
        ";

        return $this->sql->select($query);
    }

    function get_agents_tickets_solved($from, $until, $ticket_form) : array | false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final

        WITH Tickets AS (
            SELECT
            COALESCE(t1.assigned_to_id, 0) AS assigned_to_id,
            CONCAT('Sem. ', DATEPART(ISOWK, t1.created_on)) AS WeekISO,
            COUNT(*) AS TotalTickets,
            CONCAT(
                MAX(CASE WHEN t2.first_name IS NULL OR t2.first_name = '' THEN 'No asignado' ELSE t2.first_name END),
                ' ',
                MAX(CASE WHEN t2.last_name IS NULL OR t2.last_name = '' THEN '' ELSE t2.last_name END)
            ) AS AssignedUserName,
            DATEADD(wk, DATEDIFF(wk, 0, t1.created_on), 0) AS StartOfWeek,
            DATEADD(wk, DATEDIFF(wk, 0, t1.created_on), 6) AS EndOfWeek
            FROM [TG].[dbo].[mojo_tickets] t1
            LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
            WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
            AND t1.ticket_form_id = {$ticket_form}
            AND t1.status_id IN (50,60)
            GROUP BY t1.assigned_to_id, DATEPART(ISOWK, t1.created_on), t1.created_on
        )

        SELECT
          assigned_to_id,
          WeekISO,
          SUM(TotalTickets) AS TotalTickets,
          AssignedUserName,
          MIN(StartOfWeek) AS StartOfWeek,
          MAX(EndOfWeek) AS EndOfWeek
        FROM Tickets
        GROUP BY assigned_to_id, WeekISO, AssignedUserName
        ORDER BY assigned_to_id, WeekISO;
        ";
        return $this->sql->select($query);
    }

    function get_agents_tickets_solved_month($from, $until, $ticket_form) : array | false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final
        
        ;WITH Tickets AS (
            SELECT
                COALESCE(t1.assigned_to_id, 0) AS assigned_to_id,
                FORMAT(t1.created_on, 'yyyy-MM') AS MonthYear, -- Formato de año-mes
                COUNT(*) AS TotalTickets,
                CONCAT(
                    MAX(CASE WHEN t2.first_name IS NULL OR t2.first_name = '' THEN 'No asignado' ELSE t2.first_name END),
                    ' ',
                    MAX(CASE WHEN t2.last_name IS NULL OR t2.last_name = '' THEN '' ELSE t2.last_name END)
                ) AS AssignedUserName,
                DATEADD(MONTH, DATEDIFF(MONTH, 0, t1.created_on), 0) AS StartOfMonth,
                DATEADD(MONTH, DATEDIFF(MONTH, 0, t1.created_on) + 1, -1) AS EndOfMonth
            FROM [TG].[dbo].[mojo_tickets] t1
            LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
            WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
            AND t1.ticket_form_id = {$ticket_form}
            AND t1.status_id IN (50,60)
            GROUP BY t1.assigned_to_id, FORMAT(t1.created_on, 'yyyy-MM'), t1.created_on
        )
        
        SELECT
            assigned_to_id,
            MonthYear,
            SUM(TotalTickets) AS TotalTickets,
            AssignedUserName,
            MIN(StartOfMonth) AS StartOfMonth,
            MAX(EndOfMonth) AS EndOfMonth
        FROM Tickets
        GROUP BY assigned_to_id, MonthYear, AssignedUserName
        ORDER BY assigned_to_id, MonthYear;
        ";

        return $this->sql->select($query);
    }

    function get_agents_tickets_pending($from, $until, $ticket_form) : array | false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final
        
        WITH Tickets AS (
          SELECT
            COALESCE(t1.assigned_to_id, 0) AS assigned_to_id,
            CONCAT('Sem. ', DATEPART(ISOWK, t1.created_on)) AS WeekISO,
            COUNT(*) AS TotalTickets,
            CONCAT(
              MAX(CASE WHEN t2.first_name IS NULL OR t2.first_name = '' THEN 'No asignado' ELSE t2.first_name END),
              ' ',
              MAX(CASE WHEN t2.last_name IS NULL OR t2.last_name = '' THEN '' ELSE t2.last_name END)
            ) AS AssignedUserName,
            DATEADD(wk, DATEDIFF(wk, 0, t1.created_on), 0) AS StartOfWeek,
            DATEADD(wk, DATEDIFF(wk, 0, t1.created_on), 6) AS EndOfWeek
          FROM [TG].[dbo].[mojo_tickets] t1
          LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
          WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
          AND t1.ticket_form_id = {$ticket_form}
          AND t1.status_id IN (10,20,30,40)
          GROUP BY t1.assigned_to_id, DATEPART(ISOWK, t1.created_on), t1.created_on
        )
        
        SELECT
          assigned_to_id,
          WeekISO,
          SUM(TotalTickets) AS TotalTickets,
          AssignedUserName,
          MIN(StartOfWeek) AS StartOfWeek,
          MAX(EndOfWeek) AS EndOfWeek
        FROM Tickets
        GROUP BY assigned_to_id, WeekISO, AssignedUserName
        ORDER BY assigned_to_id, WeekISO;
        ";
        return $this->sql->select($query);
    }

    function get_agents_tickets_pending_month($from, $until, $ticket_form) : array | false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final
        
        ;WITH Tickets AS (
            SELECT
                COALESCE(t1.assigned_to_id, 0) AS assigned_to_id,
                FORMAT(t1.created_on, 'yyyy-MM') AS MonthYear, -- Formato de año-mes
                COUNT(*) AS TotalTickets,
                CONCAT(
                    MAX(CASE WHEN t2.first_name IS NULL OR t2.first_name = '' THEN 'No asignado' ELSE t2.first_name END),
                    ' ',
                    MAX(CASE WHEN t2.last_name IS NULL OR t2.last_name = '' THEN '' ELSE t2.last_name END)
                ) AS AssignedUserName,
                DATEADD(MONTH, DATEDIFF(MONTH, 0, t1.created_on), 0) AS StartOfMonth,
                DATEADD(MONTH, DATEDIFF(MONTH, 0, t1.created_on) + 1, -1) AS EndOfMonth
            FROM [TG].[dbo].[mojo_tickets] t1
            LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
            WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
            AND t1.ticket_form_id = {$ticket_form}
            AND t1.status_id IN (10,20,30,40)
            GROUP BY t1.assigned_to_id, FORMAT(t1.created_on, 'yyyy-MM'), t1.created_on
        )
        
        SELECT
            assigned_to_id,
            MonthYear,
            SUM(TotalTickets) AS TotalTickets,
            AssignedUserName,
            MIN(StartOfMonth) AS StartOfMonth,
            MAX(EndOfMonth) AS EndOfMonth
        FROM Tickets
        GROUP BY assigned_to_id, MonthYear, AssignedUserName
        ORDER BY assigned_to_id, MonthYear;

        ";
        return $this->sql->select($query);
    }

    function get_agents_tickets_urgent($from, $until, $ticket_form) : array | false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final
        
        WITH Tickets AS (
          SELECT
            COALESCE(t1.assigned_to_id, 0) AS assigned_to_id,
            CONCAT('Sem. ', DATEPART(ISOWK, t1.created_on)) AS WeekISO,
            COUNT(*) AS TotalTickets,
            CONCAT(
              MAX(CASE WHEN t2.first_name IS NULL OR t2.first_name = '' THEN 'No asignado' ELSE t2.first_name END),
              ' ',
              MAX(CASE WHEN t2.last_name IS NULL OR t2.last_name = '' THEN '' ELSE t2.last_name END)
            ) AS AssignedUserName,
            DATEADD(wk, DATEDIFF(wk, 0, t1.created_on), 0) AS StartOfWeek,
            DATEADD(wk, DATEDIFF(wk, 0, t1.created_on), 6) AS EndOfWeek,
            AVG(DATEDIFF(MINUTE, t1.created_on, t1.solved_on) / 60.0) AS AvgHoursToResolve
          FROM [TG].[dbo].[mojo_tickets] t1
          LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
          WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
          AND t1.ticket_form_id = {$ticket_form}
          AND t1.priority_id IN (10,20)
          GROUP BY t1.assigned_to_id, DATEPART(ISOWK, t1.created_on), t1.created_on
        )
        
        SELECT
          assigned_to_id,
          WeekISO,
          SUM(TotalTickets) AS TotalTickets,
          AssignedUserName,
          MIN(StartOfWeek) AS StartOfWeek,
          MAX(EndOfWeek) AS EndOfWeek,
          ISNULL(AVG(AvgHoursToResolve), 0) AS AvgHoursToResolve
        FROM Tickets
        GROUP BY assigned_to_id, WeekISO, AssignedUserName
        ORDER BY assigned_to_id, WeekISO;
        ";
        return $this->sql->select($query);
    }

    function get_agents_tickets_urgent_month($from, $until, $ticket_form) : array | false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final
        
        WITH Tickets AS (
            SELECT
                COALESCE(t1.assigned_to_id, 0) AS assigned_to_id,
                FORMAT(t1.created_on, 'yyyy-MM') AS MonthYear, -- Formato de año-mes
                COUNT(*) AS TotalTickets,
                CONCAT(
                    MAX(CASE WHEN t2.first_name IS NULL OR t2.first_name = '' THEN 'No asignado' ELSE t2.first_name END),
                    ' ',
                    MAX(CASE WHEN t2.last_name IS NULL OR t2.last_name = '' THEN '' ELSE t2.last_name END)
                ) AS AssignedUserName,
                DATEADD(MONTH, DATEDIFF(MONTH, 0, t1.created_on), 0) AS StartOfMonth,
                DATEADD(MONTH, DATEDIFF(MONTH, 0, t1.created_on) + 1, -1) AS EndOfMonth,
                AVG(DATEDIFF(MINUTE, t1.created_on, t1.solved_on) / 60.0) AS AvgHoursToResolve
            FROM [TG].[dbo].[mojo_tickets] t1
            LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
            WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
            AND t1.ticket_form_id = {$ticket_form}
            AND t1.priority_id IN (10,20)
            GROUP BY t1.assigned_to_id, FORMAT(t1.created_on, 'yyyy-MM'), t1.created_on
        )
        
        SELECT
            assigned_to_id,
            MonthYear,
            SUM(TotalTickets) AS TotalTickets,
            AssignedUserName,
            MIN(StartOfMonth) AS StartOfMonth,
            MAX(EndOfMonth) AS EndOfMonth,
            ISNULL(AVG(AvgHoursToResolve), 0) AS AvgHoursToResolve
        FROM Tickets
        GROUP BY assigned_to_id, MonthYear, AssignedUserName
        ORDER BY assigned_to_id, MonthYear;
        ";

        return $this->sql->select($query);
    }

    function get_agents_tickets_normal($from, $until, $ticket_form) : array | false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final
        
        WITH Tickets AS (
          SELECT
            COALESCE(t1.assigned_to_id, 0) AS assigned_to_id,
            CONCAT('Sem. ', DATEPART(ISOWK, t1.created_on)) AS WeekISO,
            COUNT(*) AS TotalTickets,
            CONCAT(
              MAX(CASE WHEN t2.first_name IS NULL OR t2.first_name = '' THEN 'No asignado' ELSE t2.first_name END),
              ' ',
              MAX(CASE WHEN t2.last_name IS NULL OR t2.last_name = '' THEN '' ELSE t2.last_name END)
            ) AS AssignedUserName,
            DATEADD(wk, DATEDIFF(wk, 0, t1.created_on), 0) AS StartOfWeek,
            DATEADD(wk, DATEDIFF(wk, 0, t1.created_on), 6) AS EndOfWeek,
            AVG(DATEDIFF(MINUTE, t1.created_on, t1.solved_on) / 60.0) AS AvgHoursToResolve
          FROM [TG].[dbo].[mojo_tickets] t1
          LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
          WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
          AND t1.ticket_form_id = {$ticket_form}
          AND t1.priority_id IN (30,40)
          GROUP BY t1.assigned_to_id, DATEPART(ISOWK, t1.created_on), t1.created_on
        )
        
        SELECT
          assigned_to_id,
          WeekISO,
          SUM(TotalTickets) AS TotalTickets,
          AssignedUserName,
          MIN(StartOfWeek) AS StartOfWeek,
          MAX(EndOfWeek) AS EndOfWeek,
          ISNULL(AVG(AvgHoursToResolve), 0) AS AvgHoursToResolve
        FROM Tickets
        GROUP BY assigned_to_id, WeekISO, AssignedUserName
        ORDER BY assigned_to_id, WeekISO;
        ";
        return $this->sql->select($query);
    }

    function get_agents_tickets_normal_month($from, $until, $ticket_form) : array | false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final
        
        ;WITH Tickets AS (
            SELECT
                COALESCE(t1.assigned_to_id, 0) AS assigned_to_id,
                FORMAT(t1.created_on, 'yyyy-MM') AS MonthYear, -- Formato de año-mes
                COUNT(*) AS TotalTickets,
                CONCAT(
                    MAX(CASE WHEN t2.first_name IS NULL OR t2.first_name = '' THEN 'No asignado' ELSE t2.first_name END),
                    ' ',
                    MAX(CASE WHEN t2.last_name IS NULL OR t2.last_name = '' THEN '' ELSE t2.last_name END)
                ) AS AssignedUserName,
                DATEADD(MONTH, DATEDIFF(MONTH, 0, t1.created_on), 0) AS StartOfMonth,
                DATEADD(MONTH, DATEDIFF(MONTH, 0, t1.created_on) + 1, -1) AS EndOfMonth,
                AVG(DATEDIFF(MINUTE, t1.created_on, t1.solved_on) / 60.0) AS AvgHoursToResolve
            FROM [TG].[dbo].[mojo_tickets] t1
            LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
            WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
            AND t1.ticket_form_id = {$ticket_form}
            AND t1.priority_id IN (30,40)
            GROUP BY t1.assigned_to_id, FORMAT(t1.created_on, 'yyyy-MM'), t1.created_on
        )
        
        SELECT
            assigned_to_id,
            MonthYear,
            SUM(TotalTickets) AS TotalTickets,
            AssignedUserName,
            MIN(StartOfMonth) AS StartOfMonth,
            MAX(EndOfMonth) AS EndOfMonth,
            ISNULL(AVG(AvgHoursToResolve), 0) AS AvgHoursToResolve
        FROM Tickets
        GROUP BY assigned_to_id, MonthYear, AssignedUserName
        ORDER BY assigned_to_id, MonthYear;
        ";

        return $this->sql->select($query);
    }

    function update_ticket_db($ticket_id) {
        $query = "UPDATE [TG].[dbo].[mojo_tickets] SET [assigned_to_id] = ? WHERE [id_mojo] = ?;";
        $params = [4351669, $ticket_id];
        return $this->sql->query($query, $params);
    }

    function get_binnacle($from, $until, $codgas) : array | false {
        $query = "SELECT
                    t1.*, t2.Nombre AS Estacion, t3.den Producto
                FROM
                    [TG].[dbo].[AuditoriaActualizacionDespachos] t1
                    LEFT JOIN [TG].[dbo].[Estaciones] t2 ON t1.codgas = t2.Codigo
                    LEFT JOIN [SG12].[dbo].[Productos] t3 ON t1.codprd = t3.cod
                WHERE t1.fchcor BETWEEN ? AND ? AND (? = 0 OR t1.codgas = ?) ORDER BY t1.fchcor DESC;";
        return ($rs = $this->sql->select($query, [$from, $until, $codgas, $codgas])) ? $rs : false ;
    }

    function get_tabla_tickets($from, $until, $codgas) : array | false {
        $query = "SELECT
                    t1.*, t2.Nombre AS Estacion, t3.den Producto
                FROM
                    [TG].[dbo].[TicketsDespachosVSReportes] t1
                    LEFT JOIN [TG].[dbo].[Estaciones] t2 ON t1.codgas = t2.Codigo
                    LEFT JOIN [SG12].[dbo].[Productos] t3 ON t1.producto = t3.cod
                WHERE t1.fch BETWEEN ? AND ? AND (? = 0 OR t1.codgas = ?) ORDER BY t1.fch DESC;";
        return ($rs = $this->sql->select($query, [$from, $until, $codgas, $codgas])) ? $rs : false ;
    }
}