<?php

namespace SecurityScanner\Core;

class AccessControlManager
{
    private Database $db;
    private Logger $logger;
    private array $roleHierarchy;
    private array $permissions;
    private array $rolePermissions;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::channel('access_control');
        $this->initializeRoleHierarchy();
        $this->initializePermissions();
        $this->initializeRolePermissions();
    }

    public function hasPermission(int $userId, string $permission, ?array $context = null): bool
    {
        try {
            $user = $this->getUserById($userId);

            if (!$user || !$user['is_active']) {
                return false;
            }

            $userPermissions = $this->getUserPermissions($userId);

            if (in_array('*', $userPermissions)) {
                return true;
            }

            if (in_array($permission, $userPermissions)) {
                if ($context) {
                    return $this->checkContextualPermission($userId, $permission, $context);
                }
                return true;
            }

            $this->logAccessAttempt($userId, $permission, false, $context);
            return false;

        } catch (\Exception $e) {
            $this->logger->error("Permission check error", [
                'user_id' => $userId,
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function hasRole(int $userId, string $role): bool
    {
        try {
            $user = $this->getUserById($userId);

            if (!$user) {
                return false;
            }

            $userRoles = $this->getUserRoles($userId);
            return in_array($role, $userRoles);

        } catch (\Exception $e) {
            $this->logger->error("Role check error", [
                'user_id' => $userId,
                'role' => $role,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function hasAnyRole(int $userId, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($userId, $role)) {
                return true;
            }
        }

        return false;
    }

    public function assignRole(int $userId, string $role, ?int $assignedBy = null): bool
    {
        try {
            if (!$this->isValidRole($role)) {
                throw new \InvalidArgumentException("Invalid role: {$role}");
            }

            $user = $this->getUserById($userId);
            if (!$user) {
                throw new \InvalidArgumentException("User not found: {$userId}");
            }

            if ($this->hasRole($userId, $role)) {
                return true; // Already has role
            }

            $this->db->insert('user_roles', [
                'user_id' => $userId,
                'role' => $role,
                'assigned_by' => $assignedBy,
                'assigned_at' => date('Y-m-d H:i:s')
            ]);

            $this->logSecurityEvent('role_assigned', [
                'user_id' => $userId,
                'role' => $role,
                'assigned_by' => $assignedBy
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error("Role assignment error", [
                'user_id' => $userId,
                'role' => $role,
                'assigned_by' => $assignedBy,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function revokeRole(int $userId, string $role, ?int $revokedBy = null): bool
    {
        try {
            $result = $this->db->execute(
                "DELETE FROM user_roles WHERE user_id = ? AND role = ?",
                [$userId, $role]
            );

            if ($result) {
                $this->logSecurityEvent('role_revoked', [
                    'user_id' => $userId,
                    'role' => $role,
                    'revoked_by' => $revokedBy
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Role revocation error", [
                'user_id' => $userId,
                'role' => $role,
                'revoked_by' => $revokedBy,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function grantPermission(int $userId, string $permission, ?array $context = null, ?int $grantedBy = null): bool
    {
        try {
            if (!$this->isValidPermission($permission)) {
                throw new \InvalidArgumentException("Invalid permission: {$permission}");
            }

            $existingPermission = $this->db->fetchRow(
                "SELECT * FROM user_permissions WHERE user_id = ? AND permission = ?",
                [$userId, $permission]
            );

            if ($existingPermission) {
                return true; // Already has permission
            }

            $this->db->insert('user_permissions', [
                'user_id' => $userId,
                'permission' => $permission,
                'context' => $context ? json_encode($context) : null,
                'granted_by' => $grantedBy,
                'granted_at' => date('Y-m-d H:i:s')
            ]);

            $this->logSecurityEvent('permission_granted', [
                'user_id' => $userId,
                'permission' => $permission,
                'context' => $context,
                'granted_by' => $grantedBy
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error("Permission grant error", [
                'user_id' => $userId,
                'permission' => $permission,
                'context' => $context,
                'granted_by' => $grantedBy,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function revokePermission(int $userId, string $permission, ?int $revokedBy = null): bool
    {
        try {
            $result = $this->db->execute(
                "DELETE FROM user_permissions WHERE user_id = ? AND permission = ?",
                [$userId, $permission]
            );

            if ($result) {
                $this->logSecurityEvent('permission_revoked', [
                    'user_id' => $userId,
                    'permission' => $permission,
                    'revoked_by' => $revokedBy
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Permission revocation error", [
                'user_id' => $userId,
                'permission' => $permission,
                'revoked_by' => $revokedBy,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function getUserPermissions(int $userId): array
    {
        try {
            $user = $this->getUserById($userId);
            if (!$user) {
                return [];
            }

            $permissions = [];

            $userRoles = $this->getUserRoles($userId);
            foreach ($userRoles as $role) {
                $rolePermissions = $this->getRolePermissions($role);
                $permissions = array_merge($permissions, $rolePermissions);
            }

            $directPermissions = $this->db->fetchAll(
                "SELECT permission FROM user_permissions WHERE user_id = ?",
                [$userId]
            );

            foreach ($directPermissions as $permission) {
                $permissions[] = $permission['permission'];
            }

            return array_unique($permissions);

        } catch (\Exception $e) {
            $this->logger->error("Get user permissions error", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    public function getUserRoles(int $userId): array
    {
        try {
            $user = $this->getUserById($userId);
            if (!$user) {
                return [];
            }

            $roles = [$user['role']];

            $additionalRoles = $this->db->fetchAll(
                "SELECT role FROM user_roles WHERE user_id = ?",
                [$userId]
            );

            foreach ($additionalRoles as $roleRecord) {
                $roles[] = $roleRecord['role'];
            }

            $allRoles = [];
            foreach ($roles as $role) {
                $allRoles[] = $role;
                $inheritedRoles = $this->getInheritedRoles($role);
                $allRoles = array_merge($allRoles, $inheritedRoles);
            }

            return array_unique($allRoles);

        } catch (\Exception $e) {
            $this->logger->error("Get user roles error", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    public function createPermissionMiddleware(string $requiredPermission, ?array $context = null): \Closure
    {
        return function($request, \Closure $next) use ($requiredPermission, $context) {
            $auth = new AuthenticationManager();
            $user = $auth->getCurrentUser();

            if (!$user) {
                throw new \UnauthorizedException('Authentication required');
            }

            if (!$this->hasPermission($user['id'], $requiredPermission, $context)) {
                $this->logAccessAttempt($user['id'], $requiredPermission, false, $context);
                throw new \ForbiddenException("Insufficient permissions: {$requiredPermission}");
            }

            $this->logAccessAttempt($user['id'], $requiredPermission, true, $context);
            return $next($request);
        };
    }

    public function createRoleMiddleware(array $allowedRoles): \Closure
    {
        return function($request, \Closure $next) use ($allowedRoles) {
            $auth = new AuthenticationManager();
            $user = $auth->getCurrentUser();

            if (!$user) {
                throw new \UnauthorizedException('Authentication required');
            }

            if (!$this->hasAnyRole($user['id'], $allowedRoles)) {
                $this->logAccessAttempt($user['id'], 'role_check', false, ['required_roles' => $allowedRoles]);
                throw new \ForbiddenException('Insufficient role permissions');
            }

            $this->logAccessAttempt($user['id'], 'role_check', true, ['required_roles' => $allowedRoles]);
            return $next($request);
        };
    }

    public function canAccessWebsite(int $userId, int $websiteId): bool
    {
        if ($this->hasPermission($userId, 'websites.manage_all')) {
            return true;
        }

        if ($this->hasPermission($userId, 'websites.manage_own')) {
            $website = $this->db->fetchRow(
                "SELECT * FROM websites WHERE id = ?",
                [$websiteId]
            );

            return $website && $website['created_by'] == $userId;
        }

        return false;
    }

    public function canModifyUser(int $currentUserId, int $targetUserId): bool
    {
        if ($currentUserId === $targetUserId) {
            return $this->hasPermission($currentUserId, 'users.manage_own');
        }

        if ($this->hasPermission($currentUserId, 'users.manage_all')) {
            return true;
        }

        $currentUserRoles = $this->getUserRoles($currentUserId);
        $targetUserRoles = $this->getUserRoles($targetUserId);

        if (in_array('admin', $currentUserRoles)) {
            return true;
        }

        if (in_array('manager', $currentUserRoles) && !in_array('admin', $targetUserRoles)) {
            return true;
        }

        return false;
    }

    public function getAccessControlReport(int $userId): array
    {
        try {
            $user = $this->getUserById($userId);
            if (!$user) {
                return [];
            }

            return [
                'user_id' => $userId,
                'email' => $user['email'],
                'is_active' => $user['is_active'],
                'primary_role' => $user['role'],
                'all_roles' => $this->getUserRoles($userId),
                'permissions' => $this->getUserPermissions($userId),
                'recent_access_attempts' => $this->getRecentAccessAttempts($userId),
                'permission_changes' => $this->getRecentPermissionChanges($userId)
            ];

        } catch (\Exception $e) {
            $this->logger->error("Access control report error", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    private function getUserById(int $userId): ?array
    {
        return $this->db->fetchRow(
            "SELECT * FROM users WHERE id = ?",
            [$userId]
        );
    }

    private function isValidRole(string $role): bool
    {
        return in_array($role, ['admin', 'manager', 'user']);
    }

    private function isValidPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions);
    }

    private function getRolePermissions(string $role): array
    {
        return $this->rolePermissions[$role] ?? [];
    }

    private function getInheritedRoles(string $role): array
    {
        $inherited = [];
        $currentRole = $role;

        while (isset($this->roleHierarchy[$currentRole])) {
            $parentRole = $this->roleHierarchy[$currentRole];
            $inherited[] = $parentRole;
            $currentRole = $parentRole;
        }

        return $inherited;
    }

    private function checkContextualPermission(int $userId, string $permission, array $context): bool
    {
        $userPermissions = $this->db->fetchAll(
            "SELECT * FROM user_permissions WHERE user_id = ? AND permission = ?",
            [$userId, $permission]
        );

        if (empty($userPermissions)) {
            return false;
        }

        foreach ($userPermissions as $userPermission) {
            if (!$userPermission['context']) {
                return true; // No context restriction
            }

            $permissionContext = json_decode($userPermission['context'], true);

            if ($this->contextMatches($context, $permissionContext)) {
                return true;
            }
        }

        return false;
    }

    private function contextMatches(array $userContext, array $permissionContext): bool
    {
        foreach ($permissionContext as $key => $value) {
            if (!isset($userContext[$key]) || $userContext[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function logAccessAttempt(int $userId, string $permission, bool $granted, ?array $context): void
    {
        $this->db->insert('access_attempts', [
            'user_id' => $userId,
            'permission' => $permission,
            'granted' => $granted,
            'context' => $context ? json_encode($context) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function getRecentAccessAttempts(int $userId, int $days = 7): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM access_attempts
             WHERE user_id = ?
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY created_at DESC
             LIMIT 50",
            [$userId, $days]
        );
    }

    private function getRecentPermissionChanges(int $userId, int $days = 30): array
    {
        $roleChanges = $this->db->fetchAll(
            "SELECT 'role' as type, role as item, assigned_by as changed_by, assigned_at as changed_at
             FROM user_roles
             WHERE user_id = ?
             AND assigned_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY assigned_at DESC",
            [$userId, $days]
        );

        $permissionChanges = $this->db->fetchAll(
            "SELECT 'permission' as type, permission as item, granted_by as changed_by, granted_at as changed_at
             FROM user_permissions
             WHERE user_id = ?
             AND granted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY granted_at DESC",
            [$userId, $days]
        );

        return array_merge($roleChanges, $permissionChanges);
    }

    private function logSecurityEvent(string $event, array $context): void
    {
        $this->logger->info("Access control event: {$event}", $context);
    }

    private function initializeRoleHierarchy(): void
    {
        $this->roleHierarchy = [
            'admin' => null,    // Admin is the highest role
            'manager' => 'admin', // Manager inherits from admin
            'user' => 'manager'  // User inherits from manager
        ];
    }

    private function initializePermissions(): void
    {
        $this->permissions = [
            // System permissions
            '*',                           // Super admin permission
            'system.admin',               // System administration
            'system.settings',            // System settings management

            // User management
            'users.view',                 // View user list
            'users.create',               // Create new users
            'users.edit',                 // Edit user details
            'users.delete',               // Delete users
            'users.manage_own',           // Manage own profile
            'users.manage_all',           // Manage all users
            'users.change_roles',         // Change user roles
            'users.view_sensitive',       // View sensitive user data

            // Website management
            'websites.view',              // View websites
            'websites.create',            // Create websites
            'websites.edit',              // Edit websites
            'websites.delete',            // Delete websites
            'websites.manage_own',        // Manage own websites
            'websites.manage_all',        // Manage all websites

            // Test management
            'tests.run',                  // Run security tests
            'tests.view_results',         // View test results
            'tests.configure',            // Configure test settings
            'tests.manage_all',           // Manage all tests

            // Notification management
            'notifications.view',         // View notifications
            'notifications.send',         // Send notifications
            'notifications.configure',    // Configure notification settings

            // Reporting
            'reports.view',               // View reports
            'reports.generate',           // Generate reports
            'reports.export',             // Export reports

            // Security
            'security.audit',             // View security audit logs
            'security.monitor',           // Monitor security events
        ];
    }

    private function initializeRolePermissions(): void
    {
        $this->rolePermissions = [
            'admin' => [
                '*' // Admins have all permissions
            ],

            'manager' => [
                'users.view',
                'users.create',
                'users.edit',
                'users.manage_all',
                'websites.view',
                'websites.create',
                'websites.edit',
                'websites.delete',
                'websites.manage_all',
                'tests.run',
                'tests.view_results',
                'tests.configure',
                'tests.manage_all',
                'notifications.view',
                'notifications.send',
                'notifications.configure',
                'reports.view',
                'reports.generate',
                'reports.export',
                'security.monitor'
            ],

            'user' => [
                'users.manage_own',
                'websites.view',
                'websites.create',
                'websites.edit',
                'websites.manage_own',
                'tests.run',
                'tests.view_results',
                'notifications.view',
                'reports.view'
            ]
        ];
    }
}