<?php
class BinnacleActivitiesModel extends Model{
    public $id;
    public $user_id;
    public $activity_date;
    public $start_hour;
    public $end_hour;
    public $title;
    public $description;
    public $created_at;

    /**
     * @param $user_id
     * @param $activity_date
     * @param $start_hour
     * @param $end_hour
     * @param $title
     * @param $description
     * @return bool
     * @throws Exception
     */
    public function addActivity($user_id, $activity_date, $start_hour, $end_hour, $title, $description) : bool {
        $query = "INSERT INTO [TG].[dbo].[tg_binnacle_activities] (user_id, activity_date, start_hour, end_hour, title, description, created_at) VALUES (?, CONVERT(datetime, ?, 101), CONVERT(datetime, ?, 102), CONVERT(datetime, ?, 102), ?, ?, GETDATE());";
        $params = [$user_id, $activity_date, $start_hour, $end_hour, $title, $description];
        return (bool)$this->sql->insert($query, $params);
    }

    /**
     * @param $activity_date
     * @param $start_hour
     * @param $end_hour
     * @param $title
     * @param $description
     * @param $id
     * @return bool
     * @throws Exception
     */
    function editActivity($activity_date, $start_hour, $end_hour, $title, $description, $id) : bool {
        $query = "UPDATE [TG].[dbo].[tg_binnacle_activities] SET activity_date = CONVERT(datetime, ?, 101), start_hour = CONVERT(datetime, ?, 102), end_hour = CONVERT(datetime, ?, 102), title = ?, description = ? WHERE id = ?;";
        $params = [$activity_date, $start_hour, $end_hour, $title, $description, $id];
        return (bool)$this->sql->update($query, $params);
    }

    /**
     * @return array|null
     * @throws Exception
     */
    function getActivities() {
        $query = "SELECT
                  id
                  ,user_id
                  ,CONVERT(date, activity_date) AS activity_date
                  ,CONVERT(varchar(5), start_hour, 108) AS start_hour
                  ,CONVERT(varchar(5), end_hour, 108) AS end_hour
                  ,title
                  ,description
                  ,created_at
                FROM [TG].[dbo].[tg_binnacle_activities]
                WHERE user_id = ?
                ORDER BY activity_date DESC, start_hour DESC;";
        $params = [$_SESSION['tg_user']['Id']];
        return $this->sql->select($query, $params);
    }

    /**
     * @param $id
     * @return array|false
     * @throws Exception
     */
    function getActivity($id) : array | false {
        $query = "SELECT
                  id
                  ,user_id
                  ,CONVERT(date, activity_date) AS activity_date
                  ,CONVERT(varchar(5), start_hour, 108) AS start_hour
                  ,CONVERT(varchar(5), end_hour, 108) AS end_hour
                  ,title
                  ,description
                  ,created_at
                FROM [TG].[dbo].[tg_binnacle_activities]
                WHERE id = ?;";
        $params = [intval($id)];
        return ($rs=$this->sql->select($query, $params)) ? $rs[0] : false ;
    }
}