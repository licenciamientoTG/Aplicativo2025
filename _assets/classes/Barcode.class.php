<?php

class Barcodes {
  public $sql;
  public $num_count;

  /*---------------------------------*/
  function __construct() {
    $this->sql = MySqlPdoHandler::getInstance();
    $this->sql->connect('hacpacka_matrix');
    $this->num_count  = 12;
  }

  /**
  * @param action
  * @return null
  */
  public function create_barcode() {
    if($barcode = $this->generateAccessCode()) {
      $query = 'INSERT INTO `matrix_barcodes`(`barcode`, `created_at`) VALUES (?, NOW());';
      $params   = array($barcode);
      if($barcode_id = $this->sql->insert($query, $params)) {
        return $barcode_id;
      } else {
        return false;
      }
    }
  }

  /**
  * @param action
  * @return null
  */
  public function generateAccessCode() {
    $accessCode = $this->generateRandomString(12);
    while($this->sql->select('SELECT * FROM `matrix_barcodes` WHERE `barcode` = ? LIMIT 1; ', array($accessCode))) {
      $accessCode = $this->generateRandomString(12);
    }
    return $accessCode;
  }

  /*---------------------------------*/
  public function generateRandomString($charCount) {
    //$availableChars = array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'M', 'N', 'P', 'R', 'T', 'W', 'X', 'Y', 'Z', '2', '3', '4', '6', '7', '8', '9');
    $availableChars = array('1', '2', '3', '4', '5', '6', '7', '8', '9');
    $retStr = '';
    for($i = 0; $i < $charCount; $i += 1) {
      $retStr .= $availableChars[rand(0, count($availableChars) - 1)];
    }
    return $retStr;
  }

}
?>