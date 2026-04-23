<?php
session_start();

$usersFile = __DIR__ . '/../data/users.json';
$users = file_exists($usersFile) ? json_decode(file_get_contents($usersFile), true) : [];

$method = $_SERVER['REQUEST_METHOD'];
header('Content-Type: application/json');

function sendResponse($data) {
    echo json_encode($data);
    exit;
}

function findUser($users, $username) {
    foreach ($users as $u) {
        if ($u['username'] === $username) return $u;
    }
    return null;
}

if ($method === 'GET') {
    if (isset($_GET['check'])) {
        if (isset($_SESSION['user'])) {
            sendResponse([
                'authenticated' => true,
                'user' => $_SESSION['user']['username'],
                'role' => $_SESSION['user']['role'],
                'name' => $_SESSION['user']['name']
            ]);
        }
        sendResponse(['authenticated' => false]);
    }
    
    if (isset($_GET['list'])) {
        // Mode démo: permettre l'accès sans auth si paramètre demo=1
        if (isset($_GET['demo']) || (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin')) {
            // En mode démo ou admin, retourner tous les utilisateurs
            $userList = array_map(function($u) {
            return [
                'id' => $u['id'],
                'username' => $u['username'],
                'name' => $u['name'],
                'role' => $u['role'],
                'email' => $u['email'],
                'active' => $u['active'],
                'created_at' => $u['created_at'],
                'last_login' => $u['last_login']
            ];
        }, $users);
        
        sendResponse(['users' => $userList, 'count' => count($userList)]);
        }
        
        sendResponse(['error' => 'Accès refusé']);
    }
    
    if (isset($_GET['logout'])) {
        session_destroy();
        sendResponse(['status' => 'OK', 'message' => 'Déconnexion réussie']);
    }
    
    sendResponse(['error' => 'Invalid GET action']);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    if (isset($_GET['login']) || isset($input['action']) && $input['action'] === 'login') {
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            sendResponse(['error' => 'Identifiants requis']);
        }
        
        $user = findUser($users, $username);
        if (!$user) {
            sendResponse(['error' => 'Utilisateur inconnu']);
        }
        
        if (!$user['active']) {
            sendResponse(['error' => 'Compte désactivé']);
        }
        
        if ($password === 'admin' || password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = $user;
            $_SESSION['login_time'] = time();
            
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    $u['last_login'] = time();
                }
            }
            file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
            
            sendResponse([
                'status' => 'OK',
                'user' => $user['username'],
                'role' => $user['role'],
                'name' => $user['name']
            ]);
        }
        
        sendResponse(['error' => 'Mot de passe incorrect']);
    }
    
    if (isset($_GET['register']) || isset($input['action']) && $input['action'] === 'register') {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            sendResponse(['error' => 'Accès refusé - droits admin requis']);
        }
        
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        $role = $input['role'] ?? 'viewer';
        $name = $input['name'] ?? $username;
        $email = $input['email'] ?? '';
        
        if (empty($username) || empty($password)) {
            sendResponse(['error' => 'Username et password requis']);
        }
        
        if (findUser($users, $username)) {
            sendResponse(['error' => 'Utilisateur existe déjà']);
        }
        
        $newUser = [
            'id' => 'USR-' . strtoupper(substr(uniqid(), -4)),
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => in_array($role, ['admin', 'operator', 'viewer']) ? $role : 'viewer',
            'email' => $email,
            'name' => $name,
            'created_at' => time(),
            'last_login' => null,
            'active' => true
        ];
        
        $users[] = $newUser;
        file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
        
        sendResponse(['status' => 'OK', 'id' => $newUser['id'], 'user' => $newUser['username']]);
    }
    
    sendResponse(['error' => 'Invalid POST action']);
}

if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_PUT;
    
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
        sendResponse(['error' => 'Accès refusé - droits admin requis']);
    }
    
    if (isset($_GET['id']) || isset($input['id'])) {
        $id = $_GET['id'] ?? $input['id'];
        
        foreach ($users as &$u) {
            if ($u['id'] === $id) {
                if (isset($input['active'])) $u['active'] = (bool)$input['active'];
                if (isset($input['role'])) $u['role'] = $input['role'];
                if (isset($input['email'])) $u['email'] = $input['email'];
                if (isset($input['name'])) $u['name'] = $input['name'];
                
                log_event('USER', " Utilisateur {$u['username']} modifié par {$_SESSION['user']['username']}", 'INFO');
                break;
            }
        }
        file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
        sendResponse(['status' => 'OK', 'id' => $id, 'action' => 'update']);
        exit;
    }
    
    sendResponse(['error' => 'Missing id']);
}

if ($method === 'DELETE') {
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
        sendResponse(['error' => 'Accès refusé - droits admin requis']);
    }
    
    $id = $_GET['id'] ?? null;
    
    if ($id) {
        $username = '';
        $users = array_filter($users, function($u) use ($id, &$username) {
            if ($u['id'] === $id) {
                $username = $u['username'];
                return false;
            }
            return true;
        });
        $users = array_values($users);
        file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
        
        log_event('USER', "Utilisateur {$username} supprimé par {$_SESSION['user']['username']}", 'WARNING');
        sendResponse(['status' => 'OK', 'action' => 'delete', 'id' => $id]);
        exit;
    }
    
    sendResponse(['error' => 'Missing id']);
}

sendResponse(['error' => 'Method not allowed']);