<?php

namespace App\Models;

use Core\Model;

class CryptoDepositIntent extends Model
{
    private function objectOrNull(mixed $value): ?\stdClass
    {
        return $value instanceof \stdClass ? $value : null;
    }

    protected static string $table = 'crypto_deposit_intents';

    public function validateIntentData(string $network, string $expectedAmount, string $address): void
    {
        $network = strtoupper(trim($network));
        if (!in_array($network, ['TRC20', 'BNB20', 'TON', 'SOL'], true)) {
            throw new \InvalidArgumentException('Unsupported crypto network: ' . $network);
        }
        if (!is_numeric($expectedAmount) || \bccomp((string)$expectedAmount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('Expected amount must be positive.');
        }
        $validAddress = match ($network) {
            'BNB20' => (bool)preg_match('/^0x[a-f0-9]{40}$/i', $address),
            'TRC20' => (bool)preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address),
            'TON' => (bool)preg_match('/^(?:0:-?[a-f0-9]{64}|[EU]Q[A-Za-z0-9_-]{46})$/', $address),
            'SOL' => (bool)preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address),
        };
        if (!$validAddress) {
            throw new \InvalidArgumentException('Invalid crypto wallet address format.');
        }
    }

    public function getOpenIntentForUser(int $userId): ?\stdClass
    {
        $sql = "SELECT * FROM `" . static::$table . "`
                WHERE user_id = :user_id AND status = 'open'
                ORDER BY id DESC LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        return $this->objectOrNull($row);
    }

    public function expireIfPassed(int $intentId): void
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT * FROM `" . static::$table . "` WHERE id = ? FOR UPDATE");
            $stmt->execute([$intentId]);
            $intent = $stmt->fetch(\PDO::FETCH_OBJ);

            $intentVars = is_object($intent) ? get_object_vars($intent) : [];
            if (is_string($intentVars['status'] ?? null) && $intentVars['status'] === 'open' && is_string($intentVars['expires_at'] ?? null) && \strtotime($intentVars['expires_at']) < \time()) {
                $stmt = $this->db->prepare("UPDATE `" . static::$table . "` SET status = 'expired', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$intentId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
        }
    }

    public function findByIdAndUser(int $id, int $userId): ?\stdClass
    {
        $sql = "SELECT * FROM `" . static::$table . "` WHERE id = :id AND user_id = :user_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $this->objectOrNull($stmt->fetch(\PDO::FETCH_OBJ));
    }

    /** Intent is loaded under the caller's transaction to prevent two hashes claiming it. */
    /** @return ?\stdClass */
    public function findOpenForUpdate(int $id, int $userId): ?\stdClass
    {
        $stmt = $this->db->prepare("SELECT * FROM `" . static::$table . "` WHERE id = ? AND user_id = ? AND status = 'open' LIMIT 1 FOR UPDATE");
        $stmt->execute([$id, $userId]);
        return $this->objectOrNull($stmt->fetch(\PDO::FETCH_OBJ));
    }

    /** Must be called inside the service transaction after deposit row creation. */
    public function claimForDeposit(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE `" . static::$table . "` SET status = 'claimed', claimed_at = NOW(), updated_at = NOW() WHERE id = ? AND user_id = ? AND status = 'open'");
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() === 1;
    }

    public function markAsClaimed(int $id): bool
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT * FROM `" . static::$table . "` WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $intent = $stmt->fetch(\PDO::FETCH_OBJ);

            $intentVars = is_object($intent) ? get_object_vars($intent) : [];
            if (($intentVars['status'] ?? null) === 'open' && is_string($intentVars['status'] ?? null)) {
                $stmt = $this->db->prepare("UPDATE `" . static::$table . "` SET status = 'claimed', claimed_at = NOW(), updated_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
                $this->db->commit();
                return true;
            }

            $this->db->rollback();
            return false;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            return false;
        }
    }

    public function findOpenByNetworkAndAmount(string $network, string $expectedAmount): ?\stdClass
    {
        $sql = "SELECT id FROM `" . static::$table . "`
                WHERE network = :network AND status = 'open' AND expected_amount = :expected_amount
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['network' => $network, 'expected_amount' => $expectedAmount]);
        return $this->objectOrNull($stmt->fetch(\PDO::FETCH_OBJ));
    }
}