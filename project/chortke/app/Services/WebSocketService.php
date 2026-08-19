<?php

declare(strict_types=1);

namespace App\Services;

use Core\Redis;
use Core\Database;

use App\Contracts\LoggerInterface;
/**
 * WebSocketService - WebSocket + Long Polling infrastructure
 * 
 * Dual implementation:
 * 1. WebSocket: Real native connection (if available)
 * 2. Long Polling: Fallback for all clients
 * 
 * Features:
 * - Room-based messaging (subscriptions)
 * - Presence tracking (online/offline)
 * - Message queue persistence
 * - Event-driven notifications
 * - Connection fallback
 */
class WebSocketService
{




    private const MESSAGE_RETENTION = 3600;       // 1 hour
    private const POLL_TIMEOUT = 20;              // ⬇ کاهش از 60 به 20 ثانیه — worker کمتر block می‌ماند
    private const MAX_MESSAGES_PER_POLL = 50;     // Max messages to return
    private const ROOM_PREFIX = 'room:';
    private const PRESENCE_PREFIX = 'presence:';
    private const QUEUE_PREFIX = 'queue:';
    private const DELAYED_QUEUE_PREFIX = 'delayed:';
    private const BATCH_SIZE = 10;                // Batch size for message delivery
    private const DELIVERY_DELAY = 30;            // 30 seconds delay before delivery
    private const POLL_INTERVAL = 500000;         // ⬇ کاهش از 2s به 0.5s — چون timeout کمتر است و BLPOP اصلی sleep را جایگزین می‌کند

    private \Core\Redis $redis;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Redis $redis,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->redis = $redis;
        $this->db = $db;
        $this->logger = $logger;

        
        }

    // ──────────────────────────────────────────────────────────────────────────
    // Room Management
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * H-09 Fix: Authorize whether a user may join / observe a given room.
     *
     * A valid room *name format* is NOT authorization. Room names are predictable
     * (user:{id}, order:{id}, task:{id}, dispute:{id}), so without an ownership check
     * any authenticated user could subscribe to another user's room and receive their
     * real-time messages, or enumerate membership. This method resolves the resource
     * behind the room and verifies the caller actually participates in it.
     *
     * Fail-closed: unknown namespaces and any lookup error deny access.
     */
    public function authorizeRoomAccess(int $userId, string $room, bool $isAdmin = false): bool
    {
        if ($userId <= 0) {
            return false;
        }

        // Staff may observe any room for moderation/support.
        if ($isAdmin) {
            return true;
        }

        $sep = strpos($room, ':');
        if ($sep === false) {
            // Non-namespaced rooms (e.g. "admin") are staff-only.
            return false;
        }

        $type = substr($room, 0, $sep);
        $id   = (int)substr($room, $sep + 1);
        if ($id <= 0) {
            return false;
        }

        switch ($type) {
            case 'user':
                // A personal room belongs to exactly one user.
                return $id === $userId;

            case 'order':
                return $this->roomOwnershipExists(
                    "SELECT 1 FROM influencer_orders WHERE id = ? AND (buyer_id = ? OR influencer_id = ?) LIMIT 1",
                    [$id, $userId, $userId]
                ) || $this->roomOwnershipExists(
                    "SELECT 1 FROM story_orders WHERE id = ? AND (customer_id = ? OR influencer_user_id = ?) LIMIT 1",
                    [$id, $userId, $userId]
                );

            case 'task':
                return $this->roomOwnershipExists(
                    "SELECT 1 FROM social_tasks WHERE id = ? AND creator_id = ? LIMIT 1",
                    [$id, $userId]
                ) || $this->roomOwnershipExists(
                    "SELECT 1 FROM social_task_executions WHERE ad_id = ? AND executor_id = ? LIMIT 1",
                    [$id, $userId]
                );

            case 'dispute':
                return $this->roomOwnershipExists(
                    "SELECT 1 FROM disputes WHERE id = ? AND (user_id = ? OR target_user_id = ?) LIMIT 1",
                    [$id, $userId, $userId]
                );

            default:
                // Fail closed: never grant access to an unrecognized room namespace.
                return false;
        }
    }

    /**
     * Ownership probe used by authorizeRoomAccess(). Returns false on any error so
     * that access is never granted on uncertainty.
     *
     * @param array<int, int|string> $params
     */
    private function roomOwnershipExists(string $sql, array $params): bool
    {
        try {
            $row = $this->db->query($sql, $params)->fetch();
            return !empty($row);
        } catch (\Throwable $e) {
            $this->logger->error('websocket.authz.lookup_failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Create or join a room
     * 
     * Room naming conventions:
     * - "user:{userId}" - Personal notifications
     * - "order:{orderId}" - Order updates
     * - "task:{taskId}" - Task notifications
     * - "admin" - Admin notifications
     */
    public function joinRoom(int $userId, string $room): bool
    {
        try {
            $key = self::ROOM_PREFIX . $room . ':members';
            $userRoomsKey = "user:{$userId}:rooms"; // 🚀 BUG-07 Fix: Reverse Index
            
            // ✅ Add user to room members
            $this->redis->sAdd($key, (string)$userId);
            
            // ✅ Add room to user's subscribed rooms (Reverse Index)
            $this->redis->sAdd($userRoomsKey, $room);
            
            // ✅ Set room expiration to 24 hours
            $this->redis->expire($key, 86400);
            $this->redis->expire($userRoomsKey, 86400);
            
            $this->logger->debug('websocket.join_room', ['user' => $userId, 'room' => $room]);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('websocket.join_room.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Leave a room
     */
    public function leaveRoom(int $userId, string $room): bool
    {
        try {
            $key = self::ROOM_PREFIX . $room . ':members';
            $userRoomsKey = "user:{$userId}:rooms";
            
            $this->redis->sRem($key, (string)$userId);
            $this->redis->sRem($userRoomsKey, $room);
            
            $this->logger->debug('websocket.leave_room', ['user' => $userId, 'room' => $room]);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('websocket.leave_room.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get all users in a room
     */
    /** @return list<int> */
    public function getRoomMembers(string $room): array
    {
        try {
            $key = self::ROOM_PREFIX . $room . ':members';
            $members = $this->redis->sMembers($key) ?? [];
            return array_map('intval', $members);
        } catch (\Throwable $e) {
            $this->logger->error('websocket.get_members.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get user's rooms with active authorization re-verification (Issue #2 Fix)
     *  @return list<string> */
    public function getUserRooms(int $userId): array
    {
        try {
            // 🚀 Use Reverse Index with active authorization re-verification
            $userRoomsKey = "user:{$userId}:rooms";
            $rooms = $this->redis->sMembers($userRoomsKey) ?? [];
            $validRooms = [];

            foreach ($rooms as $room) {
                $roomStr = str_value($room);
                if ($roomStr === '' || $roomStr === "user:{$userId}") {
                    $validRooms[] = "user:{$userId}";
                    continue;
                }

                if ($this->authorizeRoomAccess($userId, $roomStr)) {
                    $validRooms[] = $roomStr;
                } else {
                    // Revoke stale membership from Redis if access is no longer valid
                    $this->leaveRoom($userId, $roomStr);
                }
            }

            return array_values(array_unique($validRooms));
        } catch (\Throwable $e) {
            $this->logger->error('websocket.get_user_rooms.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Message Publishing
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Publish message to room (with batching and delay)
     */
    /** @param array<string, mixed> $message */
    public function publishToRoom(string $room, array $message, ?string $sender = null): bool
    {
        try {
            // ✅ Add message metadata
            $msg = array_merge($message, [
                'id' => 'msg_' . bin2hex(random_bytes(16)), // 🚀 BUG-12 Fix: Guaranteed unique ID
                'room' => $room,
                'sender' => $sender,
                'timestamp' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', time() + self::MESSAGE_RETENTION)
            ]);

            // ✅ Save to database (persistent queue)
            try {
                $this->db->query(
                    "INSERT INTO realtime_messages (room, type, payload, expires_at, created_at)
                     VALUES (?, ?, ?, ?, NOW())",
                    [$room, $msg['type'] ?? 'general', json_encode($msg), $msg['expires_at']]
                );
            } catch (\Throwable $dbEx) {
                $this->logger->error('websocket.publish.db_failed', ['error' => $dbEx->getMessage()]);
            }

            try {
                // ✅ Add to delayed queue with timestamp for batching.
                // 🚀 PERF FIX: personal ("user:{id}") notifications are delivered immediately
                // (delay 0) so the waiting BLPOP in longPoll() wakes at once. Only broadcast/
                // room messages keep the batching delay. Previously ALL messages waited
                // DELIVERY_DELAY seconds, which made BLPOP time out and pushed effective
                // delivery latency to 30–50s even for direct notifications.
                $delayedKey = self::DELAYED_QUEUE_PREFIX . $room;
                $deliveryDelay = str_starts_with($room, 'user:') ? 0 : self::DELIVERY_DELAY;
                $deliverAt = time() + $deliveryDelay;
                $this->redis->zAdd($delayedKey, $deliverAt, json_encode($msg));
                $this->redis->expire($delayedKey, self::MESSAGE_RETENTION);

                // ✅ Process any ready messages for immediate delivery (batching)
                $this->processDelayedMessages($room);
            } catch (\Throwable $redisEx) {
                $this->logger->error('websocket.redis_failed_fallback_to_db', ['error' => $redisEx->getMessage()]);
            }

            $this->logger->debug('websocket.publish_delayed', ['room' => $room, 'type' => $msg['type'] ?? 'general', 'delay' => self::DELIVERY_DELAY]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('websocket.publish.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Process delayed messages that are ready for delivery (batching)
     */
    private function processDelayedMessages(string $room): void
    {
        $delayedKey = self::DELAYED_QUEUE_PREFIX . $room;
        $queueKey = self::QUEUE_PREFIX . $room;
        $now = time();

        // ✅ Get messages ready for delivery (up to batch size)
        $readyMessages = $this->redis->zRangeByScore($delayedKey, '0', (string) $now, ['limit' => ['0', (string) self::BATCH_SIZE]]);

        if (!empty($readyMessages)) {
            // ✅ Remove from delayed queue (only the fetched messages)
            foreach ($readyMessages as $msgJson) {
                $this->redis->zRem($delayedKey, $msgJson);
            }

            // ✅ Add to regular queue for polling
            foreach ($readyMessages as $msgJson) {
                $this->redis->lPush($queueKey, $msgJson);
            }

            // ✅ Publish batch via Redis (optional - for WebSocket clients)
            $this->redis->publish($room, (string)json_encode([
                'type' => 'batch_delivery',
                'count' => count($readyMessages),
                'timestamp' => date('Y-m-d H:i:s')
            ]));

            // ✅ Limit queue size
            $this->redis->lTrim($queueKey, 0, 1000);
            $this->redis->expire($queueKey, self::MESSAGE_RETENTION);

            $this->logger->debug('websocket.batch_delivered', ['room' => $room, 'count' => count($readyMessages)]);
        }
    }

    /**
     * Send message directly to a specific user's personal room
     */
    /** @param array<string, mixed> $message */
    public function sendToUser(int $userId, array $message): bool
    {
        return $this->publishToRoom("user:{$userId}", $message);
    }

    /**
     * Broadcast to multiple users
     */
    /** @param list<int> $userIds
     *  @param array<string, mixed> $message */
    public function broadcastToUsers(array $userIds, array $message): int
    {
        $count = 0;
        foreach ($userIds as $userId) {
            if ($this->sendToUser($userId, $message)) {
                $count++;
            }
        }
        return $count;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Long Polling (Fallback)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Long polling endpoint for clients
     *
     * ── ARCHITECTURE NOTE ────────────────────────────────────────────────────
     * مشکل اصلی: busy-wait loop با usleep(2s) تا 60 ثانیه → یک PHP-FPM worker
     * را block می‌کند. با 100 کاربر همزمان → server اشباع می‌شود.
     *
     * راه‌حل اعمال‌شده (میان‌مدت):
     *   1. POLL_TIMEOUT از 60s به 20s کاهش یافت
     *   2. برای personal room از Redis BLPOP استفاده می‌شود —
     *      BLPOP یک blocking call است که توسط Redis خودش مدیریت می‌شود
     *      و PHP busy-wait نمی‌کند (CPU = 0 در حین انتظار)
     *   3. برای room‌های اضافه، fallback به polling با interval کوتاه‌تر
     *
     * راه‌حل بلندمدت پیشنهادی:
     *   → Mercure (SSE) یا Soketi (WebSocket native)
     *   → PHP-FPM pool جداگانه برای `/api/real-time/poll`
     *      با pm.max_children محدود (مثلاً 20 worker فقط برای polling)
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @param int     $userId        شناسه کاربر
     * @param ?string $lastMessageId آخرین message ID دریافت‌شده
     * @param int     $timeout       حداکثر زمان انتظار (max=20s، پیش‌فرض=20s)
     */
    /** @return array<string, mixed> */
    public function longPoll(int $userId, ?string $lastMessageId = null, int $timeout = self::POLL_TIMEOUT): array
    {
        // cap سخت: هیچ‌وقت بیش از POLL_TIMEOUT بلاک نمی‌شود
        $timeout = min($timeout, self::POLL_TIMEOUT);

        // ── الگوی تاب‌آوری دیواره محافظ (Bulkhead Resilience Pattern) ──────────
        // برای جلوگیری از مصرف تمامی ورکرهای وب توسط سشن‌های انتظار طولانی چت
        $bulkheadKey = 'bulkhead:active_polls';
        $bulkheadLimit = (int)(is_numeric(config('app.websocket.bulkhead_limit', 500)) ? config('app.websocket.bulkhead_limit', 500) : 500); // ارتقای ظرفیت سازمانی جهت پشتیبانی از هزاران کاربر فعال موبایل
        
        $redisAvailable = false;
        try {
            if ($this->redis->isAvailable()) {
                $this->redis->ping();
                $redisAvailable = true;
            }
        } catch (\Throwable $e) {}

        if ($redisAvailable) {
            $activePolls = (int)($this->redis->incr($bulkheadKey));
            // 🛠 RELIABILITY FIX: arm the TTL only when the counter is first created so it
            // behaves as a ~60s self-healing safety valve. Refreshing it on every incr let
            // leaked increments (workers dying before the finally-decr) pin the counter above
            // the limit and lock out ALL polling until traffic paused. Now leakage self-heals.
            if ($activePolls === 1) {
                $this->redis->expire($bulkheadKey, 60);
            } // TTL اضطراری
            
            if ($activePolls > $bulkheadLimit) {
                $this->redis->decr($bulkheadKey);
                $this->logger->warning('websocket.bulkhead_limit_reached', [
                    'active_polls' => $activePolls,
                    'limit' => $bulkheadLimit
                ]);
                return [
                    'ok' => true,
                    'messages' => [],
                    'count' => 0,
                    'bulkhead_limited' => true,
                    'message' => 'ظرفیت اتصال به چت در حال حاضر پر است. لطفا چند لحظه بعد تلاش کنید.'
                ];
            }
        }

        try {
            // Health Check: اگر Redis down باشد → DB fallback
            $redisDown = !$redisAvailable;
            if (!$redisAvailable) {
                $this->logger->warning('websocket.redis_down_polling_db_fallback', ['error' => 'Redis connection failed before polling']);
            }

            if ($redisDown) {
                return $this->pollFromDatabase($userId, $lastMessageId, $timeout);
            }

            // ── آماده‌سازی ────────────────────────────────────────────────────────
            $rooms = $this->getUserRooms($userId);
            $rooms[] = "user:{$userId}";   // personal room — همیشه گوش می‌دهد
            $rooms = array_unique($rooms);

            // پردازش پیام‌های delayed قبل از شروع poll
            foreach ($rooms as $room) {
                $this->processDelayedMessages($room);
            }

            // ── بررسی اول (بدون انتظار) — اگر پیام از قبل موجود است ──────────────
            $messages = $this->collectMessages($rooms, $lastMessageId);
            if (!empty($messages)) {
                return [
                    'ok'       => true,
                    'messages' => array_slice($messages, 0, self::MAX_MESSAGES_PER_POLL),
                    'count'    => count($messages),
                ];
            }

            // ── BLPOP روی personal queue — بدون busy-wait ─────────────────────────
            // BLPOP فقط یک کلید را block می‌کند و CPU را آزاد می‌گذارد.
            // وقتی پیامی می‌رسد Redis بلافاصله PHP را بیدار می‌کند.
            $personalQueueKey = self::QUEUE_PREFIX . "user:{$userId}";
            try {
                // BLPOP: منتظر ماندن تا $timeout ثانیه — اگر پیام آمد فوری برگردان
                $blResult = $this->redis->blPop($personalQueueKey, (int) $timeout);

                if (!empty($blResult)) {
                    // پیام جدید از BLPOP آمد — آن را به queue برمی‌گردانیم تا getQueueMessages بخواند
                    // (از چپ push می‌کنیم تا ترتیب حفظ شود)
                    $this->redis->lPush($personalQueueKey, $blResult[1]);

                    $messages = $this->collectMessages($rooms, $lastMessageId);
                    if (!empty($messages)) {
                        return [
                            'ok'       => true,
                            'messages' => array_slice($messages, 0, self::MAX_MESSAGES_PER_POLL),
                            'count'    => count($messages),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // BLPOP پشتیبانی نشد (نسخه قدیمی Redis) → fallback به polling
                $this->logger->warning('websocket.blpop_failed_fallback', ['error' => $e->getMessage()]);
                return $this->fallbackPolling($rooms, $lastMessageId, $timeout);
            }

            // ── Timeout — هیچ پیامی نیامد ─────────────────────────────────────────
            return [
                'ok'       => true,
                'messages' => [],
                'count'    => 0,
                'timeout'  => true,
            ];
        } finally {
            if ($redisAvailable) {
                $this->redis->decr($bulkheadKey);
            }
        }
    }

    /**
     * جمع‌آوری پیام‌های موجود در تمام roomها (بدون انتظار)
     */
    /** @param list<string> $rooms
     *  @return list<mixed> */
    private function collectMessages(array $rooms, ?string $lastMessageId): array
    {
        $messages = [];
        foreach ($rooms as $room) {
            $roomMessages = $this->getQueueMessages($room, $lastMessageId);
            if (!empty($roomMessages)) {
                $messages = array_merge($messages, $roomMessages);
            }
        }
        return $messages;
    }

    /**
     * Fallback polling برای زمانی که BLPOP در دسترس نیست
     * timeout کوتاه (max 20s) و interval 0.5s
     */
    /** @param list<string> $rooms
     *  @return array<string, mixed> */
    private function fallbackPolling(array $rooms, ?string $lastMessageId, int $timeout): array
    {
        $endTime = time() + $timeout;
        while (time() < $endTime) {
            $messages = $this->collectMessages($rooms, $lastMessageId);
            if (!empty($messages)) {
                return [
                    'ok'       => true,
                    'messages' => array_slice($messages, 0, self::MAX_MESSAGES_PER_POLL),
                    'count'    => count($messages),
                ];
            }
            usleep(self::POLL_INTERVAL); // 0.5s
        }
        return ['ok' => true, 'messages' => [], 'count' => 0, 'timeout' => true];
    }

    /**
     * DB-based Polling Fallback (when Redis is down)
     */
    /** @return array<string, mixed> */
    private function pollFromDatabase(int $userId, ?string $lastMessageId = null, int $timeout = self::POLL_TIMEOUT): array
    {
        $startTime = time();
        $endTime = $startTime + $timeout;

        while (time() < $endTime) {
            // 🔐 SECURITY + PERF FIX: without Redis we cannot know which order/task/dispute
            // rooms this user joined, so the old query (room LIKE 'order:%' ...) returned
            // EVERY user's order/task/admin messages — a data leak and an unbounded scan.
            // Fail-closed: in DB fallback we serve only the user's own personal room. Room
            // messages resume normally once Redis recovers (they are persisted in the table).
            $query = "SELECT id, payload FROM realtime_messages WHERE room = ? AND expires_at > NOW() ORDER BY id DESC LIMIT " . self::MAX_MESSAGES_PER_POLL;
            $params = ["user:{$userId}"];

            try {
                $rows = $this->db->query($query, $params)->fetchAll() ?: [];
                $messages = [];
                if (is_array($rows)) {
                    // Rows are newest-first; collect until we reach the last seen id.
                    foreach ($rows as $row) {
                        $msg = (array)(json_decode($row->payload, true) ?? []);
                        if (!$msg || !isset($msg['id'])) {
                            continue;
                        }
                        if ($lastMessageId && $msg['id'] === $lastMessageId) {
                            break;
                        }
                        $messages[] = $msg;
                    }
                    $messages = array_reverse($messages); // oldest first
                }

                if (!empty($messages)) {
                    return [
                        'ok' => true,
                        'messages' => array_slice($messages, 0, self::MAX_MESSAGES_PER_POLL),
                        'count' => count($messages),
                        'fallback' => true
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->error('websocket.poll_db_failed', ['error' => $e->getMessage()]);
                break;
            }

            usleep(self::POLL_INTERVAL);
        }

        return [
            'ok' => true,
            'messages' => [],
            'count' => 0,
            'timeout' => true,
            'fallback' => true
        ];
    }

    /**
     * Get messages from queue since lastMessageId
     */
    /** @return list<mixed> */
    private function getQueueMessages(string $room, ?string $lastMessageId = null): array
    {
        $queueKey = self::QUEUE_PREFIX . $room;
        $messages = [];

        // ✅ Get all messages from queue
        $allMessages = $this->redis->lRange($queueKey, 0, self::MAX_MESSAGES_PER_POLL - 1) ?? [];

        foreach ($allMessages as $msgJson) {
            $msg = (array)(json_decode($msgJson, true) ?? []);
            
            // ✅ Filter messages after lastMessageId
            if ($lastMessageId && $msg['id'] === $lastMessageId) {
                break;
            }

            if (isset($msg['expires_at']) && strtotime((string)(is_scalar($msg['expires_at']) ? $msg['expires_at'] : '')) > time()) {
                $messages[] = $msg;
            }
        }

        return array_reverse($messages); // Oldest first
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Presence Tracking
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Update user presence (online)
     */
    public function updatePresence(int $userId): void
    {
        $key = self::PRESENCE_PREFIX . $userId;
        // اصلاح کلیدی معماری موبایل و جلوگیری از قطع سشن در پس‌زمینه (Mobile Doze Mode Presence Guard):
        // ارتقای دینامیک زمان انقضای حضور از ۶۰ ثانیه به ۵ دقیقه (۳۰۰ ثانیه) جهت پایداری اتصال در زمان سوییچ کاربران به اپلیکیشن‌های بانکی و شبکه‌های اجتماعی
        $presenceTtl = (int)(is_numeric(config('app.websocket.presence_ttl', 300)) ? config('app.websocket.presence_ttl', 300) : 300);
        $this->redis->setex($key, $presenceTtl, json_encode([
            'user_id' => $userId,
            'online_at' => date('Y-m-d H:i:s'),
            'status' => 'online'
        ]));
    }

    /**
     * Check if user is online
     */
    public function isOnline(int $userId): bool
    {
        try {
            return $this->redis->exists(self::PRESENCE_PREFIX . $userId) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get online users in room
     */
    /** @return list<int> */
    public function getOnlineInRoom(string $room): array
    {
        $members = $this->getRoomMembers($room);
        $online = [];

        foreach ($members as $userId) {
            if ($this->isOnline($userId)) {
                $online[] = $userId;
            }
        }

        return $online;
    }

    /**
     * Get online count
     */
    public function getOnlineCount(): int
    {
        try {
            $pattern = self::PRESENCE_PREFIX . '*';
            // ✅ Using scanKeys() instead of keys() for performance
            $keys = $this->redis->scanKeys($pattern);
            return count($keys);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Mark user as offline
     */
    public function markOffline(int $userId): void
    {
        try {
            $this->redis->del(self::PRESENCE_PREFIX . $userId);
        } catch (\Throwable $e) {
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Notification Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Notify task execution submitted
     */
    public function notifyExecutionSubmitted(int $executionId, int $advertiserId, string $taskTitle): void
    {
        $this->sendToUser($advertiserId, [
            'type' => 'social_task.execution_submitted',
            'execution_id' => $executionId,
            'task_title' => $taskTitle,
            'message' => 'بررسی تسک انجام شد - پاسخ را تایید یا رد کنید.',
            'action_url' => "/admin/social-tasks/{$executionId}"
        ]);
    }

    /**
     * Notify task execution started
     */
    public function notifyExecutionStarted(int $taskId, int $executorId): void
    {
        $room = "task:{$taskId}";
        $this->publishToRoom($room, [
            'type' => 'execution_started',
            'task_id' => $taskId,
            'executor_id' => $executorId,
            'message' => 'اجرا شروع شد'
        ]);
    }

    /**
     * Notify order status changed
     */
    /** @param array<string, mixed> $details */
    public function notifyOrderStatusChanged(int $orderId, string $status, array $details = []): void
    {
        $room = "order:{$orderId}";
        $this->publishToRoom($room, array_merge([
            'type' => 'order_status_changed',
            'order_id' => $orderId,
            'status' => $status,
            'message' => "وضعیت سفارش: {$status}"
        ], $details));
    }

    /**
     * Notify listing approved
     */
    public function notifyListingApproved(int $listingId, int $sellerId): void
    {
        $this->sendToUser($sellerId, [
            'type' => 'listing_approved',
            'listing_id' => $listingId,
            'message' => 'فهرست شما تایید شد'
        ]);
    }

    /**
     * Notify dispute opened
     */
    /** @param list<int> $parties */
    public function notifyDisputeOpened(int $disputeId, array $parties): void
    {
        foreach ($parties as $userId) {
            $this->sendToUser($userId, [
                'type' => 'dispute_opened',
                'dispute_id' => $disputeId,
                'message' => 'یک درخواست نزاع باز شد'
            ]);
        }
    }

    /**
     * Notify dispute resolved
     */
    public function notifyDisputeResolved(int $disputeId, string $verdict): void
    {
        $room = "dispute:{$disputeId}";
        $this->publishToRoom($room, [
            'type' => 'dispute_resolved',
            'dispute_id' => $disputeId,
            'verdict' => $verdict,
            'message' => "نزاع تصمیم گیری شد: {$verdict}"
        ]);
    }

    /**
     * Notify payment received
     */
    public function notifyPaymentReceived(int $userId, float $amount): void
    {
        $this->sendToUser($userId, [
            'type' => 'payment_received',
            'amount' => $amount,
            'message' => "پرداخت دریافت شد: {$amount}"
        ]);
    }

    /**
     * Notify verification status
     */
    public function notifyVerificationStatus(int $userId, string $status): void
    {
        $this->sendToUser($userId, [
            'type' => 'verification_status',
            'status' => $status,
            'message' => "وضعیت تایید: {$status}"
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Maintenance
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Clean up expired messages from database (Called via cron)
     */
    public function cleanupExpiredMessages(): int
    {
        return (int)$this->db->execute(
            "DELETE FROM realtime_messages WHERE expires_at < NOW()"
        );
    }

    /**
     * Batch process delayed messages for all rooms (call this periodically)
     */
    public function processAllDelayedMessages(): int
    {
        $totalProcessed = 0;
        $pattern = self::DELAYED_QUEUE_PREFIX . '*';
        // 🚀 PROD SAFETY FIX: KEYS is O(N) and blocks the entire Redis server. Use a
        // non-blocking SCAN cursor instead (same approach as getStats()).
        $delayedKeys = [];
        $cursor = '0';
        do {
            $result = $this->redis->scan($cursor, 'MATCH', $pattern, 'COUNT', '100');
            $cursor = $result[0];
            $delayedKeys = array_merge($delayedKeys, $result[1]);
        } while ($cursor !== '0');

        foreach ($delayedKeys as $delayedKey) {
            $room = str_replace(self::DELAYED_QUEUE_PREFIX, '', $delayedKey);
            $this->processDelayedMessages($room);
            $totalProcessed++;
        }

        if ($totalProcessed > 0) {
            $this->logger->info('websocket.batch_processing', ['rooms_processed' => $totalProcessed]);
        }

        return $totalProcessed;
    }

    /** @return int */
    private function getPendingMessageCount(): int
    {
        /** @var object{count: int} $row */
        $row = $this->db->query("SELECT COUNT(*) as count FROM realtime_messages")->fetch();
        return (int)($row->count ?? 0);
    }

    /** @return array<string, mixed> */
    public function getStats(): array
    {
        $delayedPattern = self::DELAYED_QUEUE_PREFIX . '*';
        $delayedKeys = [];
        $cursor = '0';
        do {
            $result = $this->redis->scan($cursor, 'MATCH', $delayedPattern, 'COUNT', '100');
            $cursor = $result[0];
            $delayedKeys = array_merge($delayedKeys, $result[1]);
        } while ($cursor !== '0');

        $delayedCount = 0;
        foreach ($delayedKeys as $key) {
            $delayedCount += $this->redis->zCard($key);
        }

        $roomPattern = self::ROOM_PREFIX . '*:members';
        $roomKeys = [];
        $cursor = '0';
        do {
            $result = $this->redis->scan($cursor, 'MATCH', $roomPattern, 'COUNT', '100');
            $cursor = $result[0];
            $roomKeys = array_merge($roomKeys, $result[1]);
        } while ($cursor !== '0');

        return [
            'online_users' => $this->getOnlineCount(),
            'pending_messages' => $this->getPendingMessageCount(),
            'delayed_messages' => $delayedCount,
            'rooms' => count($roomKeys),
            'batch_size' => self::BATCH_SIZE,
            'delivery_delay' => self::DELIVERY_DELAY,
            'poll_timeout' => self::POLL_TIMEOUT
        ];
    }
}

