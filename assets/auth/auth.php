<?php
// auth.php - Authentication Class
require_once __DIR__ . '/../config/db_config.php';

class Auth {
    private $pdo;
    private $user = null;
    private $accessibleDomains = [];
    private $accessiblePatterns = [];
    
    public function __construct() {
        $this->pdo = getDBConnection();
        
        // Only start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if ($this->isLoggedIn()) {
            $this->loadUser();
            $this->loadAccessibleDomains();
        }
    }
    
    private function loadUser() {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $this->user = $stmt->fetch();
    }
    
    private function loadAccessibleDomains() {
        if (!$this->user) return;
        
        $role = $this->user['role'];
        
        if ($role === 'super_admin') {
            $this->accessibleDomains = ['*'];
            $this->accessiblePatterns = ['*'];
            return;
        }
        
        if ($role === 'admin') {
            $stmt = $this->pdo->query("SELECT id, domain_name, is_wildcard FROM domains WHERE status = 'active'");
            $domains = $stmt->fetchAll();
            foreach ($domains as $domain) {
                $this->accessibleDomains[] = $domain['domain_name'];
                if ($domain['is_wildcard']) {
                    $this->accessiblePatterns[] = $this->domainToPattern($domain['domain_name']);
                }
            }
            return;
        }
        
        if ($role === 'domain_admin' && $this->user['assigned_domain']) {
            $mainDomain = $this->user['assigned_domain'];
            $this->accessibleDomains[] = $mainDomain;
            $this->accessiblePatterns[] = $this->domainToPattern($mainDomain);
            
            $stmt = $this->pdo->prepare("SELECT d.domain_name, d.is_wildcard FROM domain_permissions dp 
                                         JOIN domains d ON dp.domain_id = d.id 
                                         WHERE dp.user_id = ? AND dp.can_view = 1");
            $stmt->execute([$this->user['id']]);
            $extraDomains = $stmt->fetchAll();
            foreach ($extraDomains as $domain) {
                $this->accessibleDomains[] = $domain['domain_name'];
                if ($domain['is_wildcard']) {
                    $this->accessiblePatterns[] = $this->domainToPattern($domain['domain_name']);
                }
            }
            return;
        }
        
        if ($role === 'viewer') {
            $stmt = $this->pdo->prepare("SELECT d.domain_name, d.is_wildcard FROM domain_permissions dp 
                                         JOIN domains d ON dp.domain_id = d.id 
                                         WHERE dp.user_id = ? AND dp.can_view = 1");
            $stmt->execute([$this->user['id']]);
            $domains = $stmt->fetchAll();
            foreach ($domains as $domain) {
                $this->accessibleDomains[] = $domain['domain_name'];
                if ($domain['is_wildcard']) {
                    $this->accessiblePatterns[] = $this->domainToPattern($domain['domain_name']);
                }
            }
        }
    }
    
    private function domainToPattern($domain) {
        return '%' . $domain;
    }
    
    public function canAccessDomain($domainName) {
        if (in_array('*', $this->accessibleDomains)) return true;
        if (in_array($domainName, $this->accessibleDomains)) return true;
        
        foreach ($this->accessiblePatterns as $pattern) {
            if (strpos($pattern, '%') === 0) {
                $mainDomain = substr($pattern, 1);
                if (strpos($domainName, $mainDomain) !== false && $domainName !== $mainDomain) {
                    return true;
                }
            }
        }
        return false;
    }
    
    public function filterSessionsByAccess($sessions) {
        if (in_array('*', $this->accessibleDomains)) return $sessions;
        
        $filtered = [];
        foreach ($sessions as $session) {
            $domain = $session['domain'] ?? 'unknown';
            if ($this->canAccessDomain($domain)) {
                $filtered[] = $session;
            }
        }
        return $filtered;
    }
    
    public function login($username, $password) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['assigned_domain'] = $user['assigned_domain'];
            $_SESSION['login_time'] = time();
            
            $update = $this->pdo->prepare("UPDATE users SET last_login = NOW(), last_ip = ? WHERE id = ?");
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $update->execute([$ip, $user['id']]);
            
            $this->user = $user;
            $this->loadAccessibleDomains();
            $this->logActivity('login', 'User logged in successfully');
            return true;
        }
        
        $this->logActivity('login_failed', "Failed login attempt for: {$username}");
        return false;
    }
    
    public function logout() {
        if ($this->isLoggedIn()) {
            $this->logActivity('logout', 'User logged out');
        }
        session_destroy();
        return true;
    }
    
    public function isLoggedIn() {
        if (!isset($_SESSION['user_id'])) return false;
        // Check session timeout (use constant if defined, otherwise default 3600)
        $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $timeout)) {
            $this->logout();
            return false;
        }
        return true;
    }
    
    public function getAccessibleDomains() {
        return $this->accessibleDomains;
    }
    
    public function getAccessiblePatterns() {
        return $this->accessiblePatterns;
    }
    
    public function getCurrentUser() {
        return $this->user;
    }
    
    public function logActivity($action, $details = null, $actionType = 'view') {
        if (!$this->isLoggedIn()) return;
        
        $stmt = $this->pdo->prepare("INSERT INTO user_activity (user_id, action, action_type, details, ip_address, user_agent) 
                                     VALUES (?, ?, ?, ?, ?, ?)");
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt->execute([$_SESSION['user_id'], $action, $actionType, $details, $ip, $ua]);
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: /admin/c3_login.php');
            exit;
        }
    }
    
    public function requireRole($roles) {
        $this->requireLogin();
        if (!in_array($_SESSION['role'], (array)$roles)) {
            header('HTTP/1.0 403 Forbidden');
            die('Access denied. Required role: ' . implode(', ', (array)$roles));
        }
    }
}
?>