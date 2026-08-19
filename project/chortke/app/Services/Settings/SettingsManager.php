<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use App\Contracts\LoggerInterface;
use Core\Database;
use App\Events\SettingsUpdated;

class SettingsManager
{
    private Setting $model;

    private \Core\TransactionWrapper $transactionWrapper;
    private \Core\EventDispatcher $eventDispatcher;
    private \App\Contracts\LoggerInterface $logger;

    /**
     * Centralized toObject (root-cause normalization).
     */
    private function toObject(mixed $data): ?object
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) return $data;
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }

    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \Core\EventDispatcher $eventDispatcher,
        \App\Contracts\LoggerInterface $logger,
        Setting $model
    ) {
        $this->eventDispatcher = $eventDispatcher;        $this->transactionWrapper = $transactionWrapper;
        $this->logger = $logger;

                $this->model = $model;
        }


    private function getTransactionWrapper(): \Core\TransactionWrapper
    {
        return $this->transactionWrapper;
    }
    public function set(string $key, string $value): bool
    {
        $key = trim((string)$key);
        if ($key === '' || strlen((string)$key) > 255) {
            throw new \InvalidArgumentException('Invalid setting key');
        }

        if (!is_string($value) || strlen((string)$value) > 10000) {
            throw new \InvalidArgumentException('Invalid setting value');
        }

        try {
            return $this->getTransactionWrapper()->runWithRetry(function($db) use ($key, $value) {
                $db->query("SELECT id FROM system_settings WHERE `key` = ? FOR UPDATE", [$key]);
                
                $ok = $this->model->set($key, $value);
                
                if ($ok) {
                    $this->eventDispatcher->dispatch(SettingsUpdated::class, new SettingsUpdated([$key]));
                    return true;
                }
                
                return false;
            });

        } catch (\Throwable $e) {
            $this->logger->error('settings.set_failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** @param array<string, string> $settings */
    public function setMany(array $settings): bool
    {
        if (empty($settings)) return true;

        foreach ((array)$settings as $key => $value) {
            if (!is_string($key) || trim((string)$key) === '' || strlen((string)$key) > 255) {
                throw new \InvalidArgumentException('Invalid setting key in batch');
            }

            if (!is_string($value) || strlen((string)$value) > 10000) {
                throw new \InvalidArgumentException('Invalid setting value in batch');
            }
        }

        try {
            return $this->getTransactionWrapper()->runWithRetry(function($db) use ($settings) {
                $keys = array_keys($settings);
                $placeholders = implode(',', array_fill(0, count($keys), '?'));
                $db->query("SELECT id FROM system_settings WHERE `key` IN ($placeholders) FOR UPDATE", $keys);

                $ok = $this->model->setMany($settings);
                
                if ($ok) {
                    $this->eventDispatcher->dispatch(SettingsUpdated::class, new SettingsUpdated($keys));
                    return true;
                }

                return false;
            });

        } catch (\Throwable $e) {
            $this->logger->error('settings.set_many_failed', ['keys' => array_keys($settings), 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function updateById(int $id, string $key, string $value): bool
    {
        $record = $this->toObject($this->model->find($id));
        
        if (!$record || !isset($record->id) || (string)($record->key ?? '') !== $key) {
            return false;
        }

        if (!is_string($value) || strlen((string)$value) > 10000) {
            throw new \InvalidArgumentException('Invalid setting value');
        }

        return $this->updateValueById($id, $value, $key);
    }

    public function updateValueById(int $id, string $value, ?string $key = null): bool
    {
        $ok = $this->model->updateValueById($id, $value);
        if ($ok) {
            $keys = $key ? [$key] : [];
            $this->eventDispatcher->dispatch(SettingsUpdated::class, new SettingsUpdated($keys));
        }
        return $ok;
    }
}
