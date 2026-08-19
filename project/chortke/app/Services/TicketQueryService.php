<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use Core\Database;

class TicketQueryService
{


    private Ticket $ticketModel;
    private Database $db;

    public function __construct(
        Database $db,
        Ticket $ticketModel
    ) {
        $this->db = $db;
        $this->ticketModel = $ticketModel;
    }

    /** @return list<\stdClass> */
    public function getUserTickets(int $userId, ?string $status = null, int $page = 1, int $perPage = 20): array
    {
        return $this->ticketModel->getUserTickets($userId, $status, $page, $perPage);
    }

    /** @return list<\stdClass> */
    public function getCategories(): array
    {
        return $this->db->fetchAll("SELECT * FROM ticket_categories ORDER BY id ASC") ?: [
            (object)['id' => 1, 'name' => 'پشتیبانی فنی'],
            (object)['id' => 2, 'name' => 'امور مالی'],
        ];
    }

    /** @return list<\stdClass> */
    public function quickSearchTickets(string $term, ?int $userId = null, int $limit = 5): array
    {
        return $this->db->fetchAll("SELECT t.*, u.full_name, u.email FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.subject LIKE ? ORDER BY t.created_at DESC LIMIT 10", ['%' . $term . '%']) ?: [];
    }
}
