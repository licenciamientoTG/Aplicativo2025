<?php
class PageVisitsModel extends Model {

    public static function upsertVisit(string $controller, string $method): void {
        if (!isset($_SESSION['tg_user'])) return;
        try {
            $sql = MySqlPdoHandler::getInstance();
            $sql->connect('SG12');
            $query = "
                MERGE INTO [TG].[dbo].[tg_page_visits] WITH (HOLDLOCK) AS target
                USING (
                    SELECT ? AS user_id, ? AS username, ? AS controller, ? AS method,
                           CAST(GETDATE() AS DATE) AS visit_date
                ) AS source
                ON  target.user_id    = source.user_id
                AND target.controller = source.controller
                AND target.method     = source.method
                AND target.visit_date = source.visit_date
                WHEN MATCHED THEN
                    UPDATE SET visit_count = target.visit_count + 1
                WHEN NOT MATCHED THEN
                    INSERT (user_id, username, controller, method, visit_date, visit_count)
                    VALUES (source.user_id, source.username, source.controller,
                            source.method, source.visit_date, 1);
            ";
            $sql->insert($query, [
                (int)$_SESSION['tg_user']['Id'],
                $_SESSION['tg_user']['Usuario'] ?? 'desconocido',
                $controller,
                $method,
            ]);
        } catch (\Throwable $e) {
            // Silencioso: no romper la app si falla el tracking
        }
    }

    public function getTopPages(string $from, string $to): array {
        $query = "
            SELECT controller, method,
                   SUM(visit_count)        AS total_visits,
                   COUNT(DISTINCT user_id) AS unique_users
            FROM [TG].[dbo].[tg_page_visits]
            WHERE visit_date BETWEEN ? AND ?
            GROUP BY controller, method
            ORDER BY total_visits DESC;
        ";
        return $this->sql->select($query, [$from, $to]) ?? [];
    }

    public function getTopUsers(string $from, string $to): array {
        $query = "
            SELECT user_id, username,
                   SUM(visit_count)        AS total_visits,
                   COUNT(DISTINCT CONCAT(controller, '/', method)) AS pages_visited
            FROM [TG].[dbo].[tg_page_visits]
            WHERE visit_date BETWEEN ? AND ?
            GROUP BY user_id, username
            ORDER BY total_visits DESC;
        ";
        return $this->sql->select($query, [$from, $to]) ?? [];
    }

    public function getPagesReach(string $from, string $to): array {
        $query = "
            SELECT controller, method,
                   COUNT(DISTINCT user_id) AS unique_users,
                   SUM(visit_count)        AS total_visits
            FROM [TG].[dbo].[tg_page_visits]
            WHERE visit_date BETWEEN ? AND ?
            GROUP BY controller, method
            ORDER BY unique_users DESC;
        ";
        return $this->sql->select($query, [$from, $to]) ?? [];
    }

    public function getUserPages(int $user_id, string $from, string $to): array {
        $query = "
            SELECT controller, method,
                   SUM(visit_count)  AS total_visits,
                   MIN(visit_date)   AS first_visit,
                   MAX(visit_date)   AS last_visit
            FROM [TG].[dbo].[tg_page_visits]
            WHERE user_id = ? AND visit_date BETWEEN ? AND ?
            GROUP BY controller, method
            ORDER BY total_visits DESC;
        ";
        return $this->sql->select($query, [$user_id, $from, $to]) ?? [];
    }

    public function getUserInfo(int $user_id): array|false {
        $query = "
            SELECT TOP 1 user_id, username,
                   SUM(visit_count) AS total_visits,
                   COUNT(DISTINCT CONCAT(controller, '/', method)) AS pages_visited,
                   MIN(visit_date) AS first_seen,
                   MAX(visit_date) AS last_seen
            FROM [TG].[dbo].[tg_page_visits]
            WHERE user_id = ?
            GROUP BY user_id, username;
        ";
        $rs = $this->sql->select($query, [$user_id]);
        return $rs ? $rs[0] : false;
    }

    public function getUnusedInPeriod(string $from, string $to): array {
        $query = "
            SELECT controller, method,
                   MAX(visit_date)                                          AS last_visit,
                   DATEDIFF(day, MAX(visit_date), CAST(GETDATE() AS DATE)) AS days_inactive
            FROM [TG].[dbo].[tg_page_visits]
            WHERE CONCAT(controller, '/', method) NOT IN (
                SELECT CONCAT(controller, '/', method)
                FROM [TG].[dbo].[tg_page_visits]
                WHERE visit_date BETWEEN ? AND ?
                GROUP BY controller, method
            )
            GROUP BY controller, method
            ORDER BY days_inactive DESC;
        ";
        return $this->sql->select($query, [$from, $to]) ?? [];
    }
}
