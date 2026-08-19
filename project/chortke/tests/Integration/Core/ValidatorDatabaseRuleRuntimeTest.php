<?php

declare(strict_types=1);

namespace Tests\Integration\Core;

use Core\Application;
use Core\Database;
use Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorDatabaseRuleRuntimeTest extends TestCase
{
    public function test_unique_and_exists_rules_accept_canonical_and_legacy_targets_without_diagnostics(): void
    {
        $db = Application::getInstance()->container->make(Database::class);
        $existingEmail = (string)$db->fetchColumn('SELECT email FROM users ORDER BY id LIMIT 1');
        $existingId = (int)$db->fetchColumn('SELECT id FROM users ORDER BY id LIMIT 1');

        $duplicate = new Validator(['email'=>$existingEmail], ['email'=>'required|unique:users,email'], $db);
        $this->assertTrue($duplicate->fails());
        $this->assertArrayHasKey('email', $duplicate->errors());

        $newEmail = 'validator-' . bin2hex(random_bytes(6)) . '@example.test';
        $unique = new Validator(['email'=>$newEmail], ['email'=>'required|unique:users.email'], $db);
        $this->assertFalse($unique->fails());

        $exists = new Validator(['user_id'=>$existingId], ['user_id'=>'required|exists:users,id'], $db);
        $this->assertFalse($exists->fails());

        $missing = new Validator(['user_id'=>PHP_INT_MAX], ['user_id'=>'required|exists:users.id'], $db);
        $this->assertTrue($missing->fails());

        $malformedUnique = new Validator(['email'=>$newEmail], ['email'=>'required|unique:users'], $db);
        $this->assertTrue($malformedUnique->fails());
        $malformedExists = new Validator(['user_id'=>$existingId], ['user_id'=>'required|exists:users;DROP_TABLE'], $db);
        $this->assertTrue($malformedExists->fails());
    }
}
