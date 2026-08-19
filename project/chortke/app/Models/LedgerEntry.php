<?php

namespace App\Models;

use Core\Model;

class LedgerEntry extends Model
{
    protected static string $table = 'ledger_entries';

    /** @param array<string, mixed> $data */
    public function createEntry(array $data): ?\stdClass
    {
        $transactionId = trim(str_value($data['transaction_id'] ?? ''));
        if ($transactionId === '') {
            throw new \InvalidArgumentException('LedgerEntry requires a valid transaction_id');
        }
        $data['transaction_id'] = $transactionId;

        $data['account'] = $data['account'] ?? 'unknown';
        $debitVal = str_value($data['debit'] ?? '0');
        $creditVal = str_value($data['credit'] ?? '0');

        if (\Core\ValueObjects\Money::fromString((string)('0'))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($debitVal))) || \Core\ValueObjects\Money::fromString((string)('0'))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($creditVal)))) {
            throw new \InvalidArgumentException('debit and credit must be non-negative values');
        }

        $hasDebit = \Core\ValueObjects\Money::fromString((string)($debitVal))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)('0')));
        $hasCredit = \Core\ValueObjects\Money::fromString((string)($creditVal))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)('0')));

        if (($hasDebit && $hasCredit) || (!$hasDebit && !$hasCredit)) {
            throw new \InvalidArgumentException('LedgerEntry must have either debit or credit, but not both or neither');
        }

        // A transaction lifecycle can legitimately touch the same account in
        // opposite directions (for example wallet -> locked_reserve on hold,
        // then locked_reserve -> payout on completion). Reject only an exact
        // duplicate leg, not a valid later reversal/settlement leg.
        $stmt = $this->db->prepare(
            "SELECT id FROM ledger_entries
             WHERE transaction_id = ? AND account = ? AND currency = ?
               AND debit = ? AND credit = ? LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([
            $data['transaction_id'],
            $data['account'],
            $data['currency'] ?? 'irt',
            $debitVal,
            $creditVal,
        ]);
        if ($stmt->fetch()) {
            throw new \RuntimeException('Duplicate ledger entry leg detected for transaction ' . $data['transaction_id']);
        }

        $data['debit'] = $debitVal;
        $data['credit'] = $creditVal;
        $data['currency'] = $data['currency'] ?? 'irt';
        $data['description'] = $data['description'] ?? null;
        $data['metadata'] = isset($data['metadata']) && is_array($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : ($data['metadata'] ?? null);
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');

        try {
            $id = parent::create($data);
        } catch (\PDOException $e) {
            $errCode = (string)($e->errorInfo[1] ?? '');
            if ($errCode === '1062' || $errCode === '1586' || str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), '1062')) {
                throw new \RuntimeException('Duplicate ledger entry leg detected for transaction ' . $data['transaction_id'], 0, $e);
            }
            throw $e;
        }
        return is_int($id) ? $this->find($id) : null;
    }

    /** @return list<\stdClass> */
    public function getByTransactionId(string $transactionId): array
    {
        $sql = "SELECT * FROM `" . static::$table . "` WHERE transaction_id = :transaction_id ORDER BY created_at ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['transaction_id' => $transactionId]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }
}
