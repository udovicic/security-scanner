<?php

namespace Tests\Unit\Core;

use Tests\TestCase;
use SecurityScanner\Core\Database;

class DatabaseTest extends TestCase
{
    public function test_database_is_singleton(): void
    {
        $db1 = Database::getInstance();
        $db2 = Database::getInstance();

        $this->assertSame($db1, $db2);
    }

    public function test_can_execute_simple_query(): void
    {
        $result = $this->database->query('SELECT 1 as test_value');

        $this->assertInstanceOf(\PDOStatement::class, $result);

        $row = $result->fetch();
        $this->assertEquals(1, $row['test_value']);
    }

    public function test_can_fetch_single_row(): void
    {
        $row = $this->database->fetchRow('SELECT 1 as test_value, "hello" as test_string');

        $this->assertIsArray($row);
        $this->assertEquals(1, $row['test_value']);
        $this->assertEquals('hello', $row['test_string']);
    }

    public function test_can_fetch_all_rows(): void
    {
        $rows = $this->database->fetchAll('SELECT 1 as id UNION SELECT 2 as id');

        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
        $this->assertEquals(1, $rows[0]['id']);
        $this->assertEquals(2, $rows[1]['id']);
    }

    public function test_can_fetch_single_column(): void
    {
        $value = $this->database->fetchColumn('SELECT 42 as answer');

        $this->assertEquals(42, $value);
    }

    public function test_can_insert_data(): void
    {
        // Use test table that should exist from bootstrap
        $data = [
            'name' => 'Test Website',
            'url' => 'https://test.example.com',
            'status' => 'active'
        ];

        $id = $this->database->insert('test_websites', $data);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        // Verify the data was inserted
        $inserted = $this->database->fetchRow('SELECT * FROM test_websites WHERE id = ?', [$id]);
        $this->assertEquals('Test Website', $inserted['name']);
        $this->assertEquals('https://test.example.com', $inserted['url']);
    }

    public function test_can_update_data(): void
    {
        // First insert a record
        $id = $this->database->insert('test_websites', [
            'name' => 'Original Name',
            'url' => 'https://original.com',
            'status' => 'active'
        ]);

        // Update the record
        $success = $this->database->update('test_websites',
            ['name' => 'Updated Name'],
            ['id' => $id]
        );

        $this->assertTrue($success);

        // Verify the update
        $updated = $this->database->fetchRow('SELECT * FROM test_websites WHERE id = ?', [$id]);
        $this->assertEquals('Updated Name', $updated['name']);
    }

    public function test_can_delete_data(): void
    {
        // First insert a record
        $id = $this->database->insert('test_websites', [
            'name' => 'To Be Deleted',
            'url' => 'https://delete.com'
        ]);

        // Delete the record
        $success = $this->database->delete('test_websites', ['id' => $id]);

        $this->assertTrue($success);

        // Verify it was deleted
        $deleted = $this->database->fetchRow('SELECT * FROM test_websites WHERE id = ?', [$id]);
        $this->assertNull($deleted);
    }

    public function test_can_test_connection(): void
    {
        $isConnected = $this->database->testConnection();

        $this->assertTrue($isConnected);
    }

    public function test_can_get_connection_info(): void
    {
        $info = $this->database->getConnectionInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKeys([
            'connection_name',
            'driver',
            'host',
            'port',
            'database',
            'username'
        ], $info);
    }

    public function test_can_handle_transactions(): void
    {
        $this->database->execute("
            CREATE TABLE IF NOT EXISTS test_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                value VARCHAR(255)
            )
        ");

        // Test successful transaction
        $this->database->beginTransaction();
        $this->database->insert('test_transactions', ['value' => 'test1']);
        $this->database->insert('test_transactions', ['value' => 'test2']);
        $this->database->commit();

        $count = $this->database->fetchColumn('SELECT COUNT(*) FROM test_transactions');
        $this->assertEquals(2, $count);

        // Test rollback transaction
        $this->database->beginTransaction();
        $this->database->insert('test_transactions', ['value' => 'test3']);
        $this->database->rollback();

        $count = $this->database->fetchColumn('SELECT COUNT(*) FROM test_transactions');
        $this->assertEquals(2, $count); // Should still be 2, not 3
    }
}