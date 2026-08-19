<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\WebSocketService;

/**
 * RealTimeController - Real-time messaging API endpoints
 */
class RealTimeController extends BaseApiController
{
    private WebSocketService $realTime;

    public function __construct(WebSocketService $realTime, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->realTime = $realTime;
    }

    /**
     * Long Polling endpoint
     *
     * ── محدودیت‌های امنیتی و عملکردی ────────────────────────────────────────
     * - timeout حداکثر 20 ثانیه (cap سخت در Controller و Service)
     * - PHP max_execution_time روی این endpoint به 25s تنظیم می‌شود
     * - Connection: close → جلوگیری از keep-alive و آزادسازی سریع‌تر worker
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function poll(): void
    {
        // 🛡️ CLOUD SCALABILITY FIX (Swoole / RoadRunner Runtime Guard): تشخیص خودکار سرورهای Asynchronous
        // حذف گلوگاه قفل شدن استخر ورکرهای PHP-FPM در چت بلادرنگ
        $isAsyncRuntime = isset($_SERVER['LARAVEL_OCTANE']) || isset($_SERVER['RR_MODE']) || extension_loaded('swoole');
        if (!$isAsyncRuntime) {
            // cap سخت PHP execution برای این endpoint در محیط سنتی PHP-FPM
            set_time_limit(25);
        }

        // Connection: keep-alive برای کلاینت‌های موبایل جهت بهینه‌سازی باتری و حذف سربار TLS Handshake در شبکه‌های 4G/5G
        // و Connection: close برای کلاینت‌های سنتی وب جهت آزادسازی سریع‌تر Worker
        if (!headers_sent()) {
            $isMobileClient = $this->request->header('X-App-Version') 
                || $this->request->get('app_version') 
                || str_starts_with(str_value($this->request->header('Authorization') ?? ''), 'Bearer ');

            if ($isMobileClient || $isAsyncRuntime) {
                header('Connection: keep-alive');
                header('Keep-Alive: timeout=10, max=100');
            } else {
                header('Connection: close');
            }
            header('Cache-Control: no-cache, no-store, must-revalidate');
        }

        try {
            $userId = (int)$this->userId();
            if (!$userId) {
                $this->error('Unauthorized', 401);
                return;
            }

            $lastMessageId = trim($this->request->str('last_message_id')) ?: null;

            // cap سخت: client نمی‌تواند بیش از 20 ثانیه timeout بخواهد
            $timeout = min(max($this->request->int('timeout', 20), 1), 20);

            $result = $this->realTime->longPoll($userId, $lastMessageId, $timeout);

            if (!empty($result['bulkhead_limited'])) {
                $this->error(str_value($result['message'] ?? 'ظرفیت اتصال در حال حاضر پر است.'), 429, 'BULKHEAD_LIMITED');
                return;
            }

            $this->success([
                'messages' => $result['messages'] ?? [],
                'count'    => $result['count'] ?? 0,
                'timeout'  => $result['timeout'] ?? false,
            ]);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('real_time.poll_failed', ['error' => $e->getMessage()]);
            $msg = config('app.debug') ? ('Poll failed: ' . $e->getMessage()) : 'خطا در پردازش درخواست بلادرنگ';
            $this->error($msg, 500);
        }
    }

    /**
     * Join a real-time room
     */
    public function joinRoom(): void
    {

        try {
            $userId = (int)$this->userId();
            if (!$userId) {
                $this->error('Unauthorized', 401);
                return;
            }

            $room = trim($this->request->str('room'));
            if (empty($room)) {
                $this->error('Room name required', 400);
                return;
            }

            // ✅ Validate room format (Issue #1 Fix: Support all valid room namespaces: user, order, task, dispute, room, global)
            if (!preg_match('/^(user|order|task|dispute|room|global):[0-9]+$/i', $room)) {
                $this->error('Invalid room format', 400);
                return;
            }

            // 🔐 H-09 Fix: room format ≠ authorization. Room names (user:{id}, order:{id},
            // task:{id}, dispute:{id}) are trivially enumerable, so without an ownership
            // check any authenticated user could subscribe to another user's room and
            // eavesdrop on their real-time messages. Enforce fail-closed authorization.
            $isAdmin = in_array((string)($this->currentUser()->role ?? ''), ['admin', 'super_admin'], true);
            if (!$this->realTime->authorizeRoomAccess($userId, $room, $isAdmin)) {
                $this->logger->warning('real_time.room_join_denied', [
                    'user_id' => $userId,
                    'room'    => $room,
                ]);
                $this->error('You are not allowed to join this room', 403);
                return;
            }

            $this->realTime->joinRoom($userId, $room);

            $this->logger->info('real_time.room_joined', [
                'user_id' => $userId,
                'room'    => $room
            ]);

            $this->success([
                'room' => $room,
                'msg'  => 'Subscribed to room'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('real_time.join_room_failed', ['error' => $e->getMessage()]);
            $this->error('Join failed', 500);
        }
    }

    /**
     * Leave a real-time room
     */
    public function leaveRoom(): void
    {
        try {
            $userId = (int)$this->userId();
            if (!$userId) {
                $this->error('Unauthorized', 401);
                return;
            }

            $room = trim($this->request->str('room'));
            if (empty($room)) {
                $this->error('Room name required', 400);
                return;
            }

            $this->realTime->leaveRoom($userId, $room);

            $this->logger->info('real_time.room_left', [
                'user_id' => $userId,
                'room'    => $room
            ]);

            $this->success([
                'room' => $room,
                'msg'  => 'Unsubscribed from room'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('real_time.leave_room_failed', ['error' => $e->getMessage()]);
            $this->error('Leave failed', 500);
        }
    }

    /**
     * Get members in a room
     */
    public function getRoomMembers(): void
    {
        try {
            $userId = (int)$this->userId();
            if (!$userId) {
                $this->error('Unauthorized', 401);
                return;
            }

            $room = trim((string)$this->request->param('room'));
            if (empty($room)) {
                $this->error('Room name required', 400);
                return;
            }

            // 🔐 H-09 Fix: room membership discloses who is listening; restrict to
            // participants of the room (or staff). Fail-closed for everyone else.
            $isAdmin = in_array((string)($this->currentUser()->role ?? ''), ['admin', 'super_admin'], true);
            if (!$this->realTime->authorizeRoomAccess($userId, $room, $isAdmin)) {
                $this->logger->warning('real_time.room_members_denied', [
                    'user_id' => $userId,
                    'room'    => $room,
                ]);
                $this->error('You are not allowed to access this room', 403);
                return;
            }

            $members = $this->realTime->getRoomMembers($room);

            $this->success([
                'room'    => $room,
                'members' => $members,
                'count'   => count($members)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('real_time.get_members_failed', ['error' => $e->getMessage()]);
            $this->error('Get members failed', 500);
        }
    }

    /**
     * Get all online users count
     */
    public function getOnlineUsers(): void
    {
        try {
            $onlineCount = $this->realTime->getOnlineCount();
            $this->success(['count' => $onlineCount]);
        } catch (\Exception $e) {
            $this->logger->error('real_time.get_online_failed', ['error' => $e->getMessage()]);
            $this->error('Get online count failed', 500);
        }
    }

    /**
     * Get online users in a specific room
     */
    public function getOnlineInRoom(): void
    {
        try {
            $userId = (int)$this->userId();
            if (!$userId) {
                $this->error('Unauthorized', 401);
                return;
            }

            $room = trim((string)$this->request->param('room'));
            if (empty($room)) {
                $this->error('Room name required', 400);
                return;
            }

            // 🔐 H-09 Fix: presence in a room discloses who is online there; restrict to
            // participants of the room (or staff). Fail-closed for everyone else.
            $isAdmin = in_array((string)($this->currentUser()->role ?? ''), ['admin', 'super_admin'], true);
            if (!$this->realTime->authorizeRoomAccess($userId, $room, $isAdmin)) {
                $this->logger->warning('real_time.room_presence_denied', [
                    'user_id' => $userId,
                    'room'    => $room,
                ]);
                $this->error('You are not allowed to access this room', 403);
                return;
            }

            $onlineUsers = $this->realTime->getOnlineInRoom($room);

            $this->success([
                'room'  => $room,
                'users' => $onlineUsers,
                'count' => count($onlineUsers)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('real_time.get_online_in_room_failed', ['error' => $e->getMessage()]);
            $this->error('Get online in room failed', 500);
        }
    }

    /**
     * Get real-time system stats
     */
    public function getStats(): void
    {
        try {
            $stats = $this->realTime->getStats();
            $this->success(['stats' => $stats]);
        } catch (\Exception $e) {
            $this->logger->error('real_time.get_stats_failed', ['error' => $e->getMessage()]);
            $this->error('Get stats failed', 500);
        }
    }
}
