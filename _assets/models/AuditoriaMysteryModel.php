<?php

class AuditoriaMysteryModel extends Model{
    public $id;
    public $codgas;
    public $qualification;
    public $date_mistery;
    public $date_added;
    public $user_id;

function getMysteryShopper($from, $until){
    $fromDate = DateTime::createFromFormat('Y-m-d', $from);
    $untilDate = DateTime::createFromFormat('Y-m-d', $until);
    $currentDate = clone $fromDate;
    
    $weeks = [];
    while ($currentDate <= $untilDate) {
        // Usar ISO_WEEK para consistencia
        $weekNumber = (int)$currentDate->format('W'); // ISO 8601
        $year = $currentDate->format('o'); // Año ISO (importante para semanas que cruzan años)
        $weeks[] = ['week' => $weekNumber, 'year' => $year];
        $currentDate->modify('next monday');
    }

    // Generar columnas dinámicas para el PIVOT
    $columns = [];
    foreach ($weeks as $week) {
        $columns[] = "[{$week['year']}_{$week['week']}]";
    }
    $columnsList = implode(',', $columns);

    // Construir consulta SQL dinámica con ISO_WEEK
    $query = "
     WITH AuditoriasPorSemana AS (
        SELECT 
            t1.[codgas],
            CASE 
                WHEN t1.codgas = 333 THEN 'Colosio'
                ELSE t2.abr 
            END AS abr,
            t1.qualification,
            -- Usar DATEPART(ISO_WEEK) y el año ISO correcto
            CONCAT(
                CASE 
                    -- Si es semana 1 pero es diciembre, el año ISO es el siguiente
                    WHEN DATEPART(ISO_WEEK, t1.date_mistery) >= 52 AND MONTH(t1.date_mistery) = 1 
                        THEN YEAR(t1.date_mistery) - 1
                    -- Si es semana 1 pero es enero, el año ISO es el actual
                    WHEN DATEPART(ISO_WEEK, t1.date_mistery) = 1 AND MONTH(t1.date_mistery) = 12 
                        THEN YEAR(t1.date_mistery) + 1
                    ELSE YEAR(t1.date_mistery)
                END,
                '_',
                DATEPART(ISO_WEEK, t1.date_mistery)
            ) AS column_name
        FROM [TGV2].[dbo].[AuditoriaMystery] t1
        LEFT JOIN SG12.dbo.Gasolineras t2 ON t1.codgas = t2.cod
        WHERE t1.date_mistery BETWEEN '{$from}' AND '{$until}'
        GROUP BY 
            DATEPART(ISO_WEEK, t1.date_mistery), 
            YEAR(t1.date_mistery),
            MONTH(t1.date_mistery),
            t1.codgas,
            t2.abr, 
            t1.qualification,
            t1.date_mistery
    )
    SELECT 
       *
    FROM AuditoriasPorSemana
    PIVOT (
       AVG(qualification)
        FOR column_name IN (
            {$columnsList}
        )
    ) AS PivotTable
    ORDER BY codgas;
    ";
   
    return $this->sql->select($query, []);
}


    function getMysteryShopperByDate($date){
        $query = "SELECT * FROM [TGV2].[dbo].[AuditoriaMystery] WHERE date_mistery = ?";
        return $this->sql->select($query, [$date]);
    }

    function insertMysteryShopper($data, $date){
        try{
            $user = $_SESSION['tg_user']['Id'];
            $this->sql->beginTransaction();
            $query = "INSERT INTO [TGV2].[dbo].[AuditoriaMystery] (codgas, qualification, date_mistery, date_added, user_id) VALUES (?,?,?,GETDATE(),?)";
            foreach ($data as $row) {
                if (is_numeric($row['calificacion'])) {
                    $this->sql->insert($query, [$row['codigo'], $row['calificacion'], $date,  $user]);
                }
            }
            $this->sql->commit();
            return ['success' => true];
        }catch(Exception $e){
            $this->sql->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }


        
    }
}