

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
                        AND t1.ticket_form_id = {$ticket_form}
                    ORDER BY
                        t1.created_on DESC
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

        is_null($ticket['solved_on']) ? $solved_on = "NULL" : $solved_on = "'{$ticket['solved_on']}'";
        is_null($ticket['rated_on']) ? $rated_on = "NULL" : $rated_on = "'{$ticket['rated_on']}'";
        is_null($ticket['assigned_on']) ? $assigned_on = "NULL" : $assigned_on = "'{$ticket['assigned_on']}'";


        $query = "
        DECLARE @id_mojo INT = {$ticket['id']};
        DECLARE @created_on DATETIME = '{$ticket['created_on']}';
        DECLARE @updated_on DATETIME = '{$ticket['updated_on']}';
        DECLARE @status_changed_on DATETIME = '{$ticket['status_changed_on']}';
        DECLARE @first_assigned_on DATETIME = '{$ticket['first_assigned_on']}';
        DECLARE @due_on DATETIME = '{$ticket['due_on']}';
        DECLARE @scheduled_on DATETIME = '{$ticket['scheduled_on']}';
        DECLARE @first_commented_on DATETIME = '{$ticket['first_commented_on']}';
        DECLARE @rated_on DATETIME = $rated_on;
        DECLARE @solved_on DATETIME = $solved_on;
        DECLARE @assigned_on DATETIME = $assigned_on;
        
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
                '{$ticket['is_attention_required']}', {$ticket['ticket_form_id']}, 1, '{$ticket['first_commented_on']}', '{$ticket['rating_comment']}', '{$requesting_department}', '{$solicitante}', '{$problema}')
            END
        ";

        return $this->sql->query($query);
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
                SUM(DATEDIFF(MINUTE, created_on, solved_on) / 60.0) AS total_hours_elapsed,
                AVG(DATEDIFF(MINUTE, created_on, solved_on) / 60.0) AS avg_hours_elapsed
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
                priority_id IN (30,40,50,60) AND 
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
                    SUM(DATEDIFF(MINUTE, created_on, solved_on) / 60.0) AS total_hours_elapsed,
                    AVG(DATEDIFF(MINUTE, created_on, solved_on) / 60.0) AS avg_hours_elapsed
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
                    priority_id IN (30,40,50,60) AND 
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
                    SUM(DATEDIFF(MINUTE, created_on, solved_on) / 60.0) AS total_hours_elapsed,
                    AVG(DATEDIFF(MINUTE, created_on, solved_on) / 60.0) AS avg_hours_elapsed
                FROM 
                    [TG].[dbo].[mojo_tickets] 
                WHERE 
                    created_on >= @StartDate AND 
                    created_on <= @EndDate AND 
                    priority_id IN (10,20) AND 
                    ticket_form_id = 51598
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
                    priority_id IN (30,40,50,60) AND 
                    ticket_form_id = 51598
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
                priority_id IN (30, 40) AND 
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
        FROM 
            WeekData w
        JOIN 
            WeekDates d ON w.week_number = d.week_number
        ORDER BY 
            w.week_number;
        ";
        return $this->sql->select($query);
    }

    function get_normal_tickets_month($from, $until, $ticket_form) : array|false {
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
                    priority_id IN (30,40) AND 
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
            FROM 
                MonthData m
            JOIN 
                MonthDates d ON m.year = d.year AND m.month = d.month
            ORDER BY 
                m.year, m.month;
        ";
        return $this->sql->select($query);
    }

    function get_normal_tickets_years($from, $until, $ticket_form) : array|false {
        $query = "
            DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
            DECLARE @EndDate DATE = '{$until}'; -- Fecha final

            
            ;WITH YearData AS (
                SELECT 
                    DATEPART(YEAR, created_on) AS year,
                    COUNT(*) AS tickets_qty,
                    SUM(DATEDIFF(MINUTE, created_on, solved_on) / 60.0) AS total_hours_elapsed,
                    AVG(DATEDIFF(MINUTE, created_on, solved_on) / 60.0) AS avg_hours_elapsed
                FROM 
                    [TG].[dbo].[mojo_tickets] 
                WHERE 
                    created_on >= @StartDate AND 
                    created_on <= @EndDate AND 
                    priority_id IN (30,40) AND 
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
            FROM 
                YearData y
            JOIN 
                YearDates d ON y.year = d.year
            ORDER BY 
                y.year;
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
        
        ;WITH Tickets AS (
            SELECT
                t1.assigned_to_id,
                CONCAT('Sem. ', DATEPART(ISOWK, t1.created_on)) AS WeekISO,
                COUNT(*) AS TotalTickets,
                SUM(CASE WHEN t1.status_id IN (50, 60) THEN 1 ELSE 0 END) AS ResolvedTickets, -- Tickets resueltos
                SUM(CASE WHEN t1.status_id IN (10, 20, 30, 40) THEN 1 ELSE 0 END) AS PendingTickets, -- Tickets pendientes
                SUM(CASE WHEN t1.priority_id IN (10, 20) THEN 1 ELSE 0 END) AS UrgentTickets, -- Tickets urgentes
                SUM(CASE WHEN t1.priority_id IN (30, 40) THEN 1 ELSE 0 END) AS NormalTickets, -- Tickets normales
                SUM(
                    CASE 
                        WHEN t1.solved_on IS NOT NULL 
                        THEN CAST(DATEDIFF(SECOND, t1.created_on, t1.solved_on) AS FLOAT) / 3600
                        ELSE CAST(DATEDIFF(SECOND, t1.created_on, GETDATE()) AS FLOAT) / 3600
                    END
                ) AS TotalHoursDifference, -- Sumatoria de diferencias en horas
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
            GROUP BY t1.assigned_to_id, DATEPART(ISOWK, t1.created_on), t1.created_on
        )
                
        SELECT
            assigned_to_id,
            WeekISO,
            SUM(TotalTickets) AS TotalTickets,
            SUM(ResolvedTickets) AS ResolvedTickets, -- Tickets resueltos
            SUM(PendingTickets) AS PendingTickets, -- Tickets pendientes
            SUM(UrgentTickets) AS UrgentTickets, -- Tickets urgentes
            SUM(NormalTickets) AS NormalTickets, -- Tickets normales
            SUM(TotalHoursDifference) AS TotalHoursDifference, -- Sumatoria de diferencias en horas
            CASE 
                WHEN SUM(TotalTickets) > 0 
                THEN SUM(TotalHoursDifference) / SUM(TotalTickets) 
                ELSE 0 
            END AS AverageHoursDifference, -- Promedio de tiempo en horas
            AssignedUserName,
            MIN(StartOfWeek) AS StartOfWeek,
            MAX(EndOfWeek) AS EndOfWeek
        FROM Tickets
        GROUP BY assigned_to_id, WeekISO, AssignedUserName
        ORDER BY assigned_to_id, WeekISO;
        ";

        return $this->sql->select($query);
    }

    function get_agents_tickets_total_month($from, $until, $ticket_form) : array | false {
        $query = "
        DECLARE @StartDate DATE = '{$from}'; -- Fecha inicial
        DECLARE @EndDate DATE = '{$until}'; -- Fecha final
        
        
        ;WITH Tickets AS (
            SELECT
                COALESCE(t1.assigned_to_id, 0) AS assigned_to_id,
                YEAR(t1.created_on) AS Year,
                MONTH(t1.created_on) AS Month,
                CONCAT(YEAR(t1.created_on), '-', RIGHT('0' + CAST(MONTH(t1.created_on) AS VARCHAR(2)), 2)) AS MonthName,
                COUNT(*) AS TotalTickets,
                SUM(CASE WHEN t1.status_id IN (50, 60) THEN 1 ELSE 0 END) AS ResolvedTickets, -- Tickets resueltos
                SUM(CASE WHEN t1.status_id IN (10, 20, 30, 40) THEN 1 ELSE 0 END) AS PendingTickets, -- Tickets pendientes
                SUM(CASE WHEN t1.priority_id IN (10, 20) THEN 1 ELSE 0 END) AS UrgentTickets, -- Tickets urgentes
                SUM(CASE WHEN t1.priority_id IN (30, 40) THEN 1 ELSE 0 END) AS NormalTickets, -- Tickets normales
                SUM(
                    CASE 
                        WHEN t1.solved_on IS NOT NULL 
                        THEN CAST(DATEDIFF(SECOND, t1.created_on, t1.solved_on) AS FLOAT) / 3600
                        ELSE CAST(DATEDIFF(SECOND, t1.created_on, GETDATE()) AS FLOAT) / 3600
                    END
                ) AS TotalHoursDifference, -- Sumatoria de diferencias en horas
                CONCAT(
                    MAX(CASE WHEN t2.first_name IS NULL OR t2.first_name = '' THEN 'No asignado' ELSE t2.first_name END),
                    ' ',
                    MAX(CASE WHEN t2.last_name IS NULL OR t2.last_name = '' THEN '' ELSE t2.last_name END)
                ) AS AssignedUserName,
                DATEFROMPARTS(YEAR(t1.created_on), MONTH(t1.created_on), 1) AS StartOfMonth,
                EOMONTH(t1.created_on) AS EndOfMonth
            FROM [TG].[dbo].[mojo_tickets] t1
            LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
            WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
            AND t1.ticket_form_id = {$ticket_form}
            GROUP BY 
                t1.assigned_to_id,
                YEAR(t1.created_on),
                MONTH(t1.created_on),
                DATEFROMPARTS(YEAR(t1.created_on), MONTH(t1.created_on), 1),
                EOMONTH(t1.created_on),
                CONCAT(YEAR(t1.created_on), '-', RIGHT('0' + CAST(MONTH(t1.created_on) AS VARCHAR(2)), 2))
        )
        
        SELECT
            assigned_to_id,
            MonthName,
            SUM(TotalTickets) AS TotalTickets,
            SUM(ResolvedTickets) AS ResolvedTickets, -- Tickets resueltos
            SUM(PendingTickets) AS PendingTickets, -- Tickets pendientes
            SUM(UrgentTickets) AS UrgentTickets, -- Tickets urgentes
            SUM(NormalTickets) AS NormalTickets, -- Tickets normales
            SUM(TotalHoursDifference) AS TotalHoursDifference, -- Sumatoria de diferencias en horas
            CASE 
                WHEN SUM(TotalTickets) > 0 
                THEN SUM(TotalHoursDifference) / SUM(TotalTickets) 
                ELSE 0 
            END AS AverageHoursDifference, -- Promedio de tiempo en horas
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
        
        ;WITH Tickets AS (
            SELECT
                COALESCE(t1.assigned_to_id, 0) AS assigned_to_id,
                YEAR(t1.created_on) AS Year,
                COUNT(*) AS TotalTickets,
                SUM(CASE WHEN t1.status_id IN (50, 60) THEN 1 ELSE 0 END) AS ResolvedTickets, -- Tickets resueltos
                SUM(CASE WHEN t1.status_id IN (10, 20, 30, 40) THEN 1 ELSE 0 END) AS PendingTickets, -- Tickets pendientes
                SUM(CASE WHEN t1.priority_id IN (10, 20) THEN 1 ELSE 0 END) AS UrgentTickets, -- Tickets urgentes
                SUM(CASE WHEN t1.priority_id IN (30, 40) THEN 1 ELSE 0 END) AS NormalTickets, -- Tickets normales
                SUM(
                    CASE 
                        WHEN t1.solved_on IS NOT NULL 
                        THEN CAST(DATEDIFF(SECOND, t1.created_on, t1.solved_on) AS FLOAT) / 3600
                        ELSE CAST(DATEDIFF(SECOND, t1.created_on, GETDATE()) AS FLOAT) / 3600
                    END
                ) AS TotalHoursDifference, -- Sumatoria de diferencias en horas
                CONCAT(
                    MAX(CASE WHEN t2.first_name IS NULL OR t2.first_name = '' THEN 'No asignado' ELSE t2.first_name END),
                    ' ',
                    MAX(CASE WHEN t2.last_name IS NULL OR t2.last_name = '' THEN '' ELSE t2.last_name END)
                ) AS AssignedUserName
            FROM [TG].[dbo].[mojo_tickets] t1
            LEFT JOIN [TG].[dbo].[mojo_users] t2 ON t1.assigned_to_id = t2.id_mojo
            WHERE t1.created_on >= @StartDate AND t1.created_on <= @EndDate
            AND t1.ticket_form_id = {$ticket_form}
            GROUP BY 
                t1.assigned_to_id,
                YEAR(t1.created_on)
        )
        
        SELECT
            assigned_to_id,
            Year,
            SUM(TotalTickets) AS TotalTickets,
            SUM(ResolvedTickets) AS ResolvedTickets, -- Tickets resueltos
            SUM(PendingTickets) AS PendingTickets, -- Tickets pendientes
            SUM(UrgentTickets) AS UrgentTickets, -- Tickets urgentes
            SUM(NormalTickets) AS NormalTickets, -- Tickets normales
            SUM(TotalHoursDifference) AS TotalHoursDifference, -- Sumatoria de diferencias en horas
            CASE 
                WHEN SUM(TotalTickets) > 0 
                THEN SUM(TotalHoursDifference) / SUM(TotalTickets) 
                ELSE 0 
            END AS AverageHoursDifference, -- Promedio de tiempo en horas
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
}