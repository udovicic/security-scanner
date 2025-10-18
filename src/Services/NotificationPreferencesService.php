<?php

namespace SecurityScanner\Services;

use SecurityScanner\Core\Database;
use SecurityScanner\Core\Logger;

class NotificationPreferencesService
{
    private Database $db;
    private Logger $logger;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::channel('notification_preferences');
    }

    public function setWebsitePreferences(int $websiteId, array $preferences): bool
    {
        try {
            $this->db->beginTransaction();

            $this->db->execute(
                "DELETE FROM notification_preferences WHERE website_id = ? AND test_name IS NULL",
                [$websiteId]
            );

            foreach ($preferences as $preference) {
                $this->db->insert('notification_preferences', [
                    'website_id' => $websiteId,
                    'test_name' => null,
                    'notification_type' => $preference['notification_type'],
                    'notification_channel' => $preference['notification_channel'],
                    'recipient' => $preference['recipient'],
                    'conditions' => json_encode($preference['conditions'] ?? []),
                    'is_enabled' => $preference['is_enabled'] ?? true,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            $this->db->commit();

            $this->logger->info("Website notification preferences updated", [
                'website_id' => $websiteId,
                'preferences_count' => count($preferences)
            ]);

            return true;

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->logger->error("Failed to set website preferences", [
                'website_id' => $websiteId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function setTestSpecificPreferences(int $websiteId, string $testName, array $preferences): bool
    {
        try {
            $this->db->beginTransaction();

            $this->db->execute(
                "DELETE FROM notification_preferences WHERE website_id = ? AND test_name = ?",
                [$websiteId, $testName]
            );

            foreach ($preferences as $preference) {
                $this->db->insert('notification_preferences', [
                    'website_id' => $websiteId,
                    'test_name' => $testName,
                    'notification_type' => $preference['notification_type'],
                    'notification_channel' => $preference['notification_channel'],
                    'recipient' => $preference['recipient'],
                    'conditions' => json_encode($preference['conditions'] ?? []),
                    'is_enabled' => $preference['is_enabled'] ?? true,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            $this->db->commit();

            $this->logger->info("Test-specific notification preferences updated", [
                'website_id' => $websiteId,
                'test_name' => $testName,
                'preferences_count' => count($preferences)
            ]);

            return true;

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->logger->error("Failed to set test-specific preferences", [
                'website_id' => $websiteId,
                'test_name' => $testName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getWebsitePreferences(int $websiteId): array
    {
        $preferences = $this->db->fetchAll(
            "SELECT * FROM notification_preferences
             WHERE website_id = ? AND test_name IS NULL
             ORDER BY notification_type, notification_channel",
            [$websiteId]
        );

        foreach ($preferences as &$preference) {
            $preference['conditions'] = json_decode($preference['conditions'], true) ?: [];
        }

        return $preferences;
    }

    public function getTestSpecificPreferences(int $websiteId, string $testName): array
    {
        $preferences = $this->db->fetchAll(
            "SELECT * FROM notification_preferences
             WHERE website_id = ? AND test_name = ?
             ORDER BY notification_type, notification_channel",
            [$websiteId, $testName]
        );

        foreach ($preferences as &$preference) {
            $preference['conditions'] = json_decode($preference['conditions'], true) ?: [];
        }

        return $preferences;
    }

    public function getAllPreferencesForWebsite(int $websiteId): array
    {
        $preferences = $this->db->fetchAll(
            "SELECT * FROM notification_preferences
             WHERE website_id = ?
             ORDER BY test_name, notification_type, notification_channel",
            [$websiteId]
        );

        $organized = [
            'website_level' => [],
            'test_specific' => []
        ];

        foreach ($preferences as $preference) {
            $preference['conditions'] = json_decode($preference['conditions'], true) ?: [];

            if ($preference['test_name'] === null) {
                $organized['website_level'][] = $preference;
            } else {
                if (!isset($organized['test_specific'][$preference['test_name']])) {
                    $organized['test_specific'][$preference['test_name']] = [];
                }
                $organized['test_specific'][$preference['test_name']][] = $preference;
            }
        }

        return $organized;
    }

    public function getApplicablePreferences(int $websiteId, string $testName, string $notificationType): array
    {
        $testSpecific = $this->db->fetchAll(
            "SELECT * FROM notification_preferences
             WHERE website_id = ? AND test_name = ? AND notification_type = ? AND is_enabled = 1",
            [$websiteId, $testName, $notificationType]
        );

        if (!empty($testSpecific)) {
            foreach ($testSpecific as &$preference) {
                $preference['conditions'] = json_decode($preference['conditions'], true) ?: [];
            }
            return $testSpecific;
        }

        $websiteLevel = $this->db->fetchAll(
            "SELECT * FROM notification_preferences
             WHERE website_id = ? AND test_name IS NULL AND notification_type = ? AND is_enabled = 1",
            [$websiteId, $notificationType]
        );

        foreach ($websiteLevel as &$preference) {
            $preference['conditions'] = json_decode($preference['conditions'], true) ?: [];
        }

        return $websiteLevel;
    }

    public function shouldSendNotification(int $websiteId, string $testName, string $notificationType, array $context): bool
    {
        $preferences = $this->getApplicablePreferences($websiteId, $testName, $notificationType);

        if (empty($preferences)) {
            return $this->getDefaultBehavior($notificationType);
        }

        foreach ($preferences as $preference) {
            if ($this->evaluateConditions($preference['conditions'], $context)) {
                return true;
            }
        }

        return false;
    }

    public function getNotificationRecipients(int $websiteId, string $testName, string $notificationType, array $context): array
    {
        $preferences = $this->getApplicablePreferences($websiteId, $testName, $notificationType);
        $recipients = [];

        foreach ($preferences as $preference) {
            if ($this->evaluateConditions($preference['conditions'], $context)) {
                $recipients[] = [
                    'channel' => $preference['notification_channel'],
                    'recipient' => $preference['recipient'],
                    'preference_id' => $preference['id']
                ];
            }
        }

        return array_unique($recipients, SORT_REGULAR);
    }

    public function updatePreference(int $preferenceId, array $updates): bool
    {
        $allowedFields = ['notification_channel', 'recipient', 'conditions', 'is_enabled'];
        $updateData = array_intersect_key($updates, array_flip($allowedFields));

        if (isset($updateData['conditions'])) {
            $updateData['conditions'] = json_encode($updateData['conditions']);
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        $result = $this->db->update('notification_preferences', $updateData, ['id' => $preferenceId]);

        if ($result) {
            $this->logger->info("Notification preference updated", [
                'preference_id' => $preferenceId,
                'updated_fields' => array_keys($updateData)
            ]);
        }

        return $result;
    }

    public function deletePreference(int $preferenceId): bool
    {
        $preference = $this->db->fetchRow(
            "SELECT website_id, test_name, notification_type FROM notification_preferences WHERE id = ?",
            [$preferenceId]
        );

        if (!$preference) {
            return false;
        }

        $result = $this->db->delete('notification_preferences', ['id' => $preferenceId]);

        if ($result) {
            $this->logger->info("Notification preference deleted", [
                'preference_id' => $preferenceId,
                'website_id' => $preference['website_id'],
                'test_name' => $preference['test_name'],
                'notification_type' => $preference['notification_type']
            ]);
        }

        return $result;
    }

    public function getPreferencesStatistics(): array
    {
        return [
            'total_preferences' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM notification_preferences"
            ),
            'enabled_preferences' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM notification_preferences WHERE is_enabled = 1"
            ),
            'website_level' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM notification_preferences WHERE test_name IS NULL"
            ),
            'test_specific' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM notification_preferences WHERE test_name IS NOT NULL"
            ),
            'by_channel' => $this->getPreferencesByChannel(),
            'by_type' => $this->getPreferencesByType(),
            'websites_with_preferences' => $this->db->fetchColumn(
                "SELECT COUNT(DISTINCT website_id) FROM notification_preferences"
            )
        ];
    }

    public function copyPreferencesFromWebsite(int $sourceWebsiteId, int $targetWebsiteId): bool
    {
        try {
            $sourcePreferences = $this->getAllPreferencesForWebsite($sourceWebsiteId);

            $this->db->beginTransaction();

            foreach ($sourcePreferences['website_level'] as $preference) {
                unset($preference['id'], $preference['created_at'], $preference['updated_at']);
                $preference['website_id'] = $targetWebsiteId;
                $preference['conditions'] = json_encode($preference['conditions']);
                $preference['created_at'] = date('Y-m-d H:i:s');
                $preference['updated_at'] = date('Y-m-d H:i:s');

                $this->db->insert('notification_preferences', $preference);
            }

            foreach ($sourcePreferences['test_specific'] as $testName => $testPreferences) {
                foreach ($testPreferences as $preference) {
                    unset($preference['id'], $preference['created_at'], $preference['updated_at']);
                    $preference['website_id'] = $targetWebsiteId;
                    $preference['conditions'] = json_encode($preference['conditions']);
                    $preference['created_at'] = date('Y-m-d H:i:s');
                    $preference['updated_at'] = date('Y-m-d H:i:s');

                    $this->db->insert('notification_preferences', $preference);
                }
            }

            $this->db->commit();

            $this->logger->info("Notification preferences copied", [
                'source_website_id' => $sourceWebsiteId,
                'target_website_id' => $targetWebsiteId
            ]);

            return true;

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->logger->error("Failed to copy notification preferences", [
                'source_website_id' => $sourceWebsiteId,
                'target_website_id' => $targetWebsiteId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function evaluateConditions(array $conditions, array $context): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? 'equals';
            $value = $condition['value'] ?? '';

            $contextValue = $context[$field] ?? null;

            switch ($operator) {
                case 'equals':
                    if ($contextValue != $value) return false;
                    break;

                case 'not_equals':
                    if ($contextValue == $value) return false;
                    break;

                case 'greater_than':
                    if (!is_numeric($contextValue) || !is_numeric($value) || $contextValue <= $value) return false;
                    break;

                case 'less_than':
                    if (!is_numeric($contextValue) || !is_numeric($value) || $contextValue >= $value) return false;
                    break;

                case 'contains':
                    if (!is_string($contextValue) || strpos($contextValue, $value) === false) return false;
                    break;

                case 'not_contains':
                    if (!is_string($contextValue) || strpos($contextValue, $value) !== false) return false;
                    break;

                case 'in_array':
                    if (!is_array($value) || !in_array($contextValue, $value)) return false;
                    break;

                case 'not_in_array':
                    if (!is_array($value) || in_array($contextValue, $value)) return false;
                    break;

                default:
                    return false;
            }
        }

        return true;
    }

    private function getDefaultBehavior(string $notificationType): bool
    {
        $defaults = [
            'test_failure' => true,
            'recovery' => true,
            'escalation' => true,
            'scheduled_report' => false
        ];

        return $defaults[$notificationType] ?? false;
    }

    private function getPreferencesByChannel(): array
    {
        $results = $this->db->fetchAll(
            "SELECT notification_channel, COUNT(*) as count
             FROM notification_preferences
             GROUP BY notification_channel"
        );

        $channels = [];
        foreach ($results as $result) {
            $channels[$result['notification_channel']] = (int)$result['count'];
        }

        return $channels;
    }

    private function getPreferencesByType(): array
    {
        $results = $this->db->fetchAll(
            "SELECT notification_type, COUNT(*) as count
             FROM notification_preferences
             GROUP BY notification_type"
        );

        $types = [];
        foreach ($results as $result) {
            $types[$result['notification_type']] = (int)$result['count'];
        }

        return $types;
    }
}