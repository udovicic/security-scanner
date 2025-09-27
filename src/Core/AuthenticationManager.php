<?php

namespace SecurityScanner\Core;

class AuthenticationManager
{
    private Database $db;
    private Logger $logger;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->db = Database::getInstance();
        $this->logger = new Logger('authentication');

        $this->config = array_merge([
            'password_min_length' => 8,
            'password_require_uppercase' => true,
            'password_require_lowercase' => true,
            'password_require_numbers' => true,
            'password_require_symbols' => true,
            'max_login_attempts' => 5,
            'lockout_duration_minutes' => 30,
            'session_lifetime_hours' => 24,
            'session_regenerate_interval_minutes' => 30,
            'password_hash_algo' => PASSWORD_ARGON2ID,
            'remember_me_duration_days' => 30,
            'require_email_verification' => true,
            'enforce_password_history' => 5,
            'password_expiry_days' => 90
        ], $config);

        $this->configureSession();
    }

    public function register(array $userData): array
    {
        try {
            $this->validateRegistrationData($userData);

            if ($this->userExists($userData['email'])) {
                return [
                    'success' => false,
                    'error' => 'User with this email already exists',
                    'code' => 'USER_EXISTS'
                ];
            }

            $passwordValidation = $this->validatePassword($userData['password']);
            if (!$passwordValidation['valid']) {
                return [
                    'success' => false,
                    'error' => $passwordValidation['message'],
                    'code' => 'INVALID_PASSWORD'
                ];
            }

            $hashedPassword = $this->hashPassword($userData['password']);
            $verificationToken = $this->generateSecureToken();

            $userId = $this->db->insert('users', [
                'email' => strtolower(trim($userData['email'])),
                'password_hash' => $hashedPassword,
                'first_name' => $userData['first_name'] ?? '',
                'last_name' => $userData['last_name'] ?? '',
                'role' => $userData['role'] ?? 'user',
                'email_verification_token' => $verificationToken,
                'email_verified' => !$this->config['require_email_verification'],
                'password_changed_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $this->logSecurityEvent('user_registered', [
                'user_id' => $userId,
                'email' => $userData['email'],
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            $result = [
                'success' => true,
                'user_id' => $userId,
                'message' => 'User registered successfully'
            ];

            if ($this->config['require_email_verification']) {
                $result['verification_token'] = $verificationToken;
                $result['message'] .= '. Please verify your email address.';
            }

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Registration failed", [
                'error' => $e->getMessage(),
                'email' => $userData['email'] ?? 'unknown'
            ]);

            return [
                'success' => false,
                'error' => 'Registration failed. Please try again.',
                'code' => 'REGISTRATION_ERROR'
            ];
        }
    }

    public function login(string $email, string $password, bool $rememberMe = false): array
    {
        try {
            $email = strtolower(trim($email));
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            if ($this->isAccountLocked($email, $ipAddress)) {
                $this->logSecurityEvent('login_blocked_locked_account', [
                    'email' => $email,
                    'ip_address' => $ipAddress
                ]);

                return [
                    'success' => false,
                    'error' => 'Account is temporarily locked due to multiple failed login attempts',
                    'code' => 'ACCOUNT_LOCKED'
                ];
            }

            $user = $this->getUserByEmail($email);

            if (!$user || !$this->verifyPassword($password, $user['password_hash'])) {
                $this->recordFailedLogin($email, $ipAddress);

                $this->logSecurityEvent('login_failed', [
                    'email' => $email,
                    'ip_address' => $ipAddress,
                    'user_exists' => $user !== null
                ]);

                return [
                    'success' => false,
                    'error' => 'Invalid email or password',
                    'code' => 'INVALID_CREDENTIALS'
                ];
            }

            if (!$user['email_verified'] && $this->config['require_email_verification']) {
                return [
                    'success' => false,
                    'error' => 'Please verify your email address before logging in',
                    'code' => 'EMAIL_NOT_VERIFIED'
                ];
            }

            if (!$user['is_active']) {
                $this->logSecurityEvent('login_blocked_inactive_user', [
                    'user_id' => $user['id'],
                    'email' => $email,
                    'ip_address' => $ipAddress
                ]);

                return [
                    'success' => false,
                    'error' => 'Your account has been deactivated',
                    'code' => 'ACCOUNT_INACTIVE'
                ];
            }

            if ($this->isPasswordExpired($user)) {
                return [
                    'success' => false,
                    'error' => 'Your password has expired. Please reset your password.',
                    'code' => 'PASSWORD_EXPIRED',
                    'user_id' => $user['id']
                ];
            }

            $this->clearFailedLogins($email, $ipAddress);

            $sessionData = $this->createSession($user, $rememberMe);

            $this->updateLastLogin($user['id'], $ipAddress);

            $this->logSecurityEvent('login_successful', [
                'user_id' => $user['id'],
                'email' => $email,
                'ip_address' => $ipAddress,
                'remember_me' => $rememberMe
            ]);

            return [
                'success' => true,
                'user' => $this->sanitizeUserData($user),
                'session' => $sessionData,
                'message' => 'Login successful'
            ];

        } catch (\Exception $e) {
            $this->logger->error("Login error", [
                'error' => $e->getMessage(),
                'email' => $email,
                'ip_address' => $ipAddress ?? 'unknown'
            ]);

            return [
                'success' => false,
                'error' => 'Login failed. Please try again.',
                'code' => 'LOGIN_ERROR'
            ];
        }
    }

    public function logout(?string $sessionToken = null): bool
    {
        try {
            $sessionToken = $sessionToken ?? $this->getCurrentSessionToken();

            if ($sessionToken) {
                $session = $this->getSessionByToken($sessionToken);

                if ($session) {
                    $this->invalidateSession($sessionToken);

                    $this->logSecurityEvent('logout', [
                        'user_id' => $session['user_id'],
                        'session_token_hash' => hash('sha256', $sessionToken),
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                }
            }

            $this->destroySessionData();

            return true;

        } catch (\Exception $e) {
            $this->logger->error("Logout error", [
                'error' => $e->getMessage(),
                'session_token_hash' => $sessionToken ? hash('sha256', $sessionToken) : null
            ]);

            return false;
        }
    }

    public function verifySession(string $sessionToken): ?array
    {
        try {
            $session = $this->getSessionByToken($sessionToken);

            if (!$session) {
                return null;
            }

            if ($this->isSessionExpired($session)) {
                $this->invalidateSession($sessionToken);
                return null;
            }

            if ($this->shouldRegenerateSession($session)) {
                $newToken = $this->regenerateSession($sessionToken);
                $session['token'] = $newToken;
            }

            $this->updateSessionActivity($sessionToken);

            return $session;

        } catch (\Exception $e) {
            $this->logger->error("Session verification error", [
                'error' => $e->getMessage(),
                'session_token_hash' => hash('sha256', $sessionToken)
            ]);

            return null;
        }
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        try {
            $user = $this->getUserById($userId);

            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            if (!$this->verifyPassword($currentPassword, $user['password_hash'])) {
                $this->logSecurityEvent('password_change_failed', [
                    'user_id' => $userId,
                    'reason' => 'invalid_current_password',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);

                return [
                    'success' => false,
                    'error' => 'Current password is incorrect',
                    'code' => 'INVALID_CURRENT_PASSWORD'
                ];
            }

            $passwordValidation = $this->validatePassword($newPassword);
            if (!$passwordValidation['valid']) {
                return [
                    'success' => false,
                    'error' => $passwordValidation['message'],
                    'code' => 'INVALID_NEW_PASSWORD'
                ];
            }

            if ($this->isPasswordInHistory($userId, $newPassword)) {
                return [
                    'success' => false,
                    'error' => 'You cannot reuse one of your recent passwords',
                    'code' => 'PASSWORD_REUSED'
                ];
            }

            $newHashedPassword = $this->hashPassword($newPassword);

            $this->db->beginTransaction();

            $this->addPasswordToHistory($userId, $user['password_hash']);

            $this->db->update('users', [
                'password_hash' => $newHashedPassword,
                'password_changed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $userId]);

            $this->invalidateAllUserSessions($userId, $this->getCurrentSessionToken());

            $this->db->commit();

            $this->logSecurityEvent('password_changed', [
                'user_id' => $userId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            return [
                'success' => true,
                'message' => 'Password changed successfully'
            ];

        } catch (\Exception $e) {
            $this->db->rollback();

            $this->logger->error("Password change error", [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);

            return [
                'success' => false,
                'error' => 'Password change failed. Please try again.',
                'code' => 'PASSWORD_CHANGE_ERROR'
            ];
        }
    }

    public function initiatePasswordReset(string $email): array
    {
        try {
            $email = strtolower(trim($email));
            $user = $this->getUserByEmail($email);

            $resetToken = $this->generateSecureToken();
            $expiresAt = date('Y-m-d H:i:s', time() + (2 * 3600)); // 2 hours

            if ($user) {
                $this->db->insert('password_reset_tokens', [
                    'user_id' => $user['id'],
                    'token' => hash('sha256', $resetToken),
                    'expires_at' => $expiresAt,
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                $this->logSecurityEvent('password_reset_requested', [
                    'user_id' => $user['id'],
                    'email' => $email,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            } else {
                $this->logSecurityEvent('password_reset_requested_nonexistent_user', [
                    'email' => $email,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            }

            return [
                'success' => true,
                'message' => 'If an account exists with this email, a password reset link has been sent.',
                'reset_token' => $user ? $resetToken : null
            ];

        } catch (\Exception $e) {
            $this->logger->error("Password reset initiation error", [
                'error' => $e->getMessage(),
                'email' => $email
            ]);

            return [
                'success' => false,
                'error' => 'Password reset failed. Please try again.',
                'code' => 'PASSWORD_RESET_ERROR'
            ];
        }
    }

    public function resetPassword(string $token, string $newPassword): array
    {
        try {
            $tokenHash = hash('sha256', $token);
            $resetRecord = $this->db->fetchRow(
                "SELECT * FROM password_reset_tokens WHERE token = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1",
                [$tokenHash]
            );

            if (!$resetRecord) {
                $this->logSecurityEvent('password_reset_failed', [
                    'reason' => 'invalid_or_expired_token',
                    'token_hash' => $tokenHash,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);

                return [
                    'success' => false,
                    'error' => 'Invalid or expired reset token',
                    'code' => 'INVALID_RESET_TOKEN'
                ];
            }

            $passwordValidation = $this->validatePassword($newPassword);
            if (!$passwordValidation['valid']) {
                return [
                    'success' => false,
                    'error' => $passwordValidation['message'],
                    'code' => 'INVALID_PASSWORD'
                ];
            }

            $userId = $resetRecord['user_id'];

            if ($this->isPasswordInHistory($userId, $newPassword)) {
                return [
                    'success' => false,
                    'error' => 'You cannot reuse one of your recent passwords',
                    'code' => 'PASSWORD_REUSED'
                ];
            }

            $newHashedPassword = $this->hashPassword($newPassword);
            $user = $this->getUserById($userId);

            $this->db->beginTransaction();

            $this->addPasswordToHistory($userId, $user['password_hash']);

            $this->db->update('users', [
                'password_hash' => $newHashedPassword,
                'password_changed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $userId]);

            $this->db->execute(
                "DELETE FROM password_reset_tokens WHERE user_id = ?",
                [$userId]
            );

            $this->invalidateAllUserSessions($userId);

            $this->db->commit();

            $this->logSecurityEvent('password_reset_completed', [
                'user_id' => $userId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            return [
                'success' => true,
                'message' => 'Password reset successfully'
            ];

        } catch (\Exception $e) {
            $this->db->rollback();

            $this->logger->error("Password reset error", [
                'error' => $e->getMessage(),
                'token_hash' => hash('sha256', $token)
            ]);

            return [
                'success' => false,
                'error' => 'Password reset failed. Please try again.',
                'code' => 'PASSWORD_RESET_ERROR'
            ];
        }
    }

    public function verifyEmail(string $token): array
    {
        try {
            $user = $this->db->fetchRow(
                "SELECT * FROM users WHERE email_verification_token = ? AND email_verified = 0",
                [$token]
            );

            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'Invalid verification token',
                    'code' => 'INVALID_VERIFICATION_TOKEN'
                ];
            }

            $this->db->update('users', [
                'email_verified' => 1,
                'email_verification_token' => null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $user['id']]);

            $this->logSecurityEvent('email_verified', [
                'user_id' => $user['id'],
                'email' => $user['email'],
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            return [
                'success' => true,
                'message' => 'Email verified successfully'
            ];

        } catch (\Exception $e) {
            $this->logger->error("Email verification error", [
                'error' => $e->getMessage(),
                'token' => $token
            ]);

            return [
                'success' => false,
                'error' => 'Email verification failed. Please try again.',
                'code' => 'EMAIL_VERIFICATION_ERROR'
            ];
        }
    }

    public function getCurrentUser(): ?array
    {
        $sessionToken = $this->getCurrentSessionToken();

        if (!$sessionToken) {
            return null;
        }

        $session = $this->verifySession($sessionToken);

        if (!$session) {
            return null;
        }

        $user = $this->getUserById($session['user_id']);

        return $user ? $this->sanitizeUserData($user) : null;
    }

    public function requireAuthentication(): array
    {
        $user = $this->getCurrentUser();

        if (!$user) {
            throw new \UnauthorizedException('Authentication required');
        }

        return $user;
    }

    public function requireRole(array $allowedRoles): array
    {
        $user = $this->requireAuthentication();

        if (!in_array($user['role'], $allowedRoles)) {
            throw new \ForbiddenException('Insufficient permissions');
        }

        return $user;
    }

    private function validateRegistrationData(array $data): void
    {
        $required = ['email', 'password'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Field '{$field}' is required");
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }
    }

    private function validatePassword(string $password): array
    {
        $errors = [];

        if (strlen($password) < $this->config['password_min_length']) {
            $errors[] = "Password must be at least {$this->config['password_min_length']} characters long";
        }

        if ($this->config['password_require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        if ($this->config['password_require_lowercase'] && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }

        if ($this->config['password_require_numbers'] && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        if ($this->config['password_require_symbols'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }

        return [
            'valid' => empty($errors),
            'message' => implode('. ', $errors)
        ];
    }

    private function hashPassword(string $password): string
    {
        return password_hash($password, $this->config['password_hash_algo']);
    }

    private function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    private function generateSecureToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    private function configureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_secure', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.gc_maxlifetime', $this->config['session_lifetime_hours'] * 3600);

            session_start();
        }
    }

    private function createSession(array $user, bool $rememberMe): array
    {
        $sessionToken = $this->generateSecureToken();
        $expiresAt = date('Y-m-d H:i:s', time() + ($this->config['session_lifetime_hours'] * 3600));

        $sessionId = $this->db->insert('user_sessions', [
            'user_id' => $user['id'],
            'session_token' => hash('sha256', $sessionToken),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'expires_at' => $expiresAt,
            'remember_me' => $rememberMe,
            'created_at' => date('Y-m-d H:i:s'),
            'last_activity' => date('Y-m-d H:i:s')
        ]);

        $_SESSION['session_token'] = $sessionToken;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['last_regeneration'] = time();

        if ($rememberMe) {
            $cookieExpire = time() + ($this->config['remember_me_duration_days'] * 24 * 3600);
            setcookie('remember_token', $sessionToken, $cookieExpire, '/', '', true, true);
        }

        return [
            'session_id' => $sessionId,
            'token' => $sessionToken,
            'expires_at' => $expiresAt
        ];
    }

    private function getCurrentSessionToken(): ?string
    {
        return $_SESSION['session_token'] ?? $_COOKIE['remember_token'] ?? null;
    }

    private function getSessionByToken(string $token): ?array
    {
        return $this->db->fetchRow(
            "SELECT * FROM user_sessions WHERE session_token = ? AND expires_at > NOW()",
            [hash('sha256', $token)]
        );
    }

    private function getUserByEmail(string $email): ?array
    {
        return $this->db->fetchRow(
            "SELECT * FROM users WHERE email = ?",
            [$email]
        );
    }

    private function getUserById(int $id): ?array
    {
        return $this->db->fetchRow(
            "SELECT * FROM users WHERE id = ?",
            [$id]
        );
    }

    private function userExists(string $email): bool
    {
        return $this->getUserByEmail($email) !== null;
    }

    private function isAccountLocked(string $email, string $ipAddress): bool
    {
        $lockoutDuration = $this->config['lockout_duration_minutes'] * 60;
        $maxAttempts = $this->config['max_login_attempts'];

        $attempts = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM failed_login_attempts
             WHERE (email = ? OR ip_address = ?)
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$email, $ipAddress, $lockoutDuration]
        );

        return $attempts >= $maxAttempts;
    }

    private function recordFailedLogin(string $email, string $ipAddress): void
    {
        $this->db->insert('failed_login_attempts', [
            'email' => $email,
            'ip_address' => $ipAddress,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function clearFailedLogins(string $email, string $ipAddress): void
    {
        $this->db->execute(
            "DELETE FROM failed_login_attempts WHERE email = ? OR ip_address = ?",
            [$email, $ipAddress]
        );
    }

    private function updateLastLogin(int $userId, string $ipAddress): void
    {
        $this->db->update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ipAddress,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $userId]);
    }

    private function isSessionExpired(array $session): bool
    {
        return strtotime($session['expires_at']) < time();
    }

    private function shouldRegenerateSession(array $session): bool
    {
        $lastRegeneration = $_SESSION['last_regeneration'] ?? 0;
        $regenerateInterval = $this->config['session_regenerate_interval_minutes'] * 60;

        return (time() - $lastRegeneration) > $regenerateInterval;
    }

    private function regenerateSession(string $oldToken): string
    {
        $newToken = $this->generateSecureToken();

        $this->db->update('user_sessions', [
            'session_token' => hash('sha256', $newToken),
            'last_activity' => date('Y-m-d H:i:s')
        ], ['session_token' => hash('sha256', $oldToken)]);

        $_SESSION['session_token'] = $newToken;
        $_SESSION['last_regeneration'] = time();

        return $newToken;
    }

    private function updateSessionActivity(string $token): void
    {
        $this->db->update('user_sessions', [
            'last_activity' => date('Y-m-d H:i:s')
        ], ['session_token' => hash('sha256', $token)]);
    }

    private function invalidateSession(string $token): void
    {
        $this->db->delete('user_sessions', [
            'session_token' => hash('sha256', $token)
        ]);
    }

    private function invalidateAllUserSessions(int $userId, ?string $exceptToken = null): void
    {
        if ($exceptToken) {
            $this->db->execute(
                "DELETE FROM user_sessions WHERE user_id = ? AND session_token != ?",
                [$userId, hash('sha256', $exceptToken)]
            );
        } else {
            $this->db->delete('user_sessions', ['user_id' => $userId]);
        }
    }

    private function destroySessionData(): void
    {
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/', '', true, true);
        }

        session_destroy();
    }

    private function isPasswordExpired(array $user): bool
    {
        if ($this->config['password_expiry_days'] <= 0) {
            return false;
        }

        $passwordChangedAt = strtotime($user['password_changed_at']);
        $expiryTime = $passwordChangedAt + ($this->config['password_expiry_days'] * 24 * 3600);

        return time() > $expiryTime;
    }

    private function isPasswordInHistory(int $userId, string $password): bool
    {
        if ($this->config['enforce_password_history'] <= 0) {
            return false;
        }

        $passwordHashes = $this->db->fetchAll(
            "SELECT password_hash FROM password_history
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$userId, $this->config['enforce_password_history']]
        );

        foreach ($passwordHashes as $record) {
            if (password_verify($password, $record['password_hash'])) {
                return true;
            }
        }

        return false;
    }

    private function addPasswordToHistory(int $userId, string $passwordHash): void
    {
        $this->db->insert('password_history', [
            'user_id' => $userId,
            'password_hash' => $passwordHash,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $this->db->execute(
            "DELETE FROM password_history
             WHERE user_id = ?
             AND id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM password_history
                     WHERE user_id = ?
                     ORDER BY created_at DESC
                     LIMIT ?
                 ) AS recent
             )",
            [$userId, $userId, $this->config['enforce_password_history']]
        );
    }

    private function sanitizeUserData(array $user): array
    {
        unset($user['password_hash'], $user['email_verification_token']);
        return $user;
    }

    private function logSecurityEvent(string $event, array $context): void
    {
        $this->logger->info("Security event: {$event}", $context);
    }
}