<?php
class Database {
    private $serverName = "NTBKSYM023";
    private $connectionInfo = array(
        "Database" => "gestordocumento",
        "TrustServerCertificate" => true,
        "Encrypt" => false,
        "CharacterSet" => "UTF-8"
    );
    private $conn;

    public function connect() {
        try {
            $this->conn = sqlsrv_connect($this->serverName, $this->connectionInfo);
            
            if ($this->conn === false) {
                $errors = sqlsrv_errors();
                error_log("SQL Server connection failed: " . print_r($errors, true));
                return false;
            }
            return $this->conn;
        } catch (Exception $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return false;
        }
    }

    public function secure_query($sql, $params = []) {
        if (!$this->conn) {
            if (!$this->connect()) {
                return false;
            }
        }
        
        $stmt = sqlsrv_prepare($this->conn, $sql, $params);
        if ($stmt === false) {
            error_log("Query preparation failed: " . print_r(sqlsrv_errors(), true));
            return false;
        }
        
        if (!sqlsrv_execute($stmt)) {
            error_log("Query execution failed: " . print_r(sqlsrv_errors(), true));
            return false;
        }
        
        return $stmt;
    }
    
    public function get_last_error() {
        return sqlsrv_errors();
    }
}
?>