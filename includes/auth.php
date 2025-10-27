<?php
session_start();

class Auth {
    private $db;
    
    public function __construct() {
        require_once __DIR__ . '/../config/database.php';
        $this->db = new Database();
    }
    
    public function login($username, $password) {
        // Prevenir timing attacks
        $username = $this->sanitize_input($username);
        
        $sql = "SELECT id, username, password_hash, perfil, nombre_completo 
                FROM usuarios 
                WHERE username = ? AND activo = 1";
        
        $stmt = $this->db->secure_query($sql, array(&$username));
        
        if ($stmt && $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (password_verify($password, $user['password_hash'])) {
                // Regenerar ID de sesión para prevenir fixation attacks
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['perfil'] = $user['perfil'];
                $_SESSION['nombre_completo'] = $user['nombre_completo'];
                $_SESSION['logged_in'] = true;
                
                $this->log_access($user['id']);
                return true;
            }
        }
        
        // Delay para prevenir brute force
        sleep(1);
        return false;
    }
    
    private function sanitize_input($data) {
        return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }
    
    private function log_access($user_id) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $sql = "INSERT INTO logs_acceso (usuario_id, ip_address) VALUES (?, ?)";
        $this->db->secure_query($sql, array(&$user_id, &$ip));
    }
    
    public function is_logged_in() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    public function logout() {
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
    
    public function require_auth() {
        if (!$this->is_logged_in()) {
            header('Location: /gestordocumento/pages/login.php');
            exit;
        }
    }
    
    public function require_admin() {
        $this->require_auth();
        if ($_SESSION['perfil'] !== 'administrador') {
            header('HTTP/1.0 403 Forbidden');
            exit('Acceso denegado. Se requiere perfil de administrador.');
        }
    }
}
?>