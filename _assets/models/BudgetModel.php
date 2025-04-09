<?php
class BudgetModel extends Model{
    public $Id;
    public $codgas;
    public $budget_monthy;
    public $codprd;
    public $date_budget;
    public $date_added;
    public $year;
    public $month;

    function getBudget( $mouth,$year)
    {
        $query = "SELECT *  FROM [TGV2].[dbo].[Budget]  where [year] = ?  and [month] =?";
        $params = [$year,$mouth];
        return ($this->sql->select($query,$params)) ?: false ;
    }

    function insertBudgetData($data){
        try{
            $this->sql->beginTransaction();
            $query = "INSERT INTO [TGV2].[dbo].[Budget] ([codgas],[budget_monthy],[codprd],[date_budget],[date_added],[year],[month]
            ) VALUES (?,?,?,?,?,?,?)";
            foreach ($data as $row) {
                    $this->sql->insert($query, [$row['codgas'], $row['budget_monthy'], $row['codprd'], $row['date_budget'],$row['date_added'],   $row['year'], $row['month']]);
            }
            $this->sql->commit();
            return ['success' => true];
        }catch(Exception $e){
            $this->sql->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }

    }


}