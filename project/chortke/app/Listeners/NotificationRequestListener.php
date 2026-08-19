<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\LoggerInterface;
use Core\Container;

/**
 * NotificationRequestListener
 *
 * Centralized handler for all notification request events.
 * Supports multiple event shapes for flexibility.
 */
class NotificationRequestListener
{
    private Container $container;
    private LoggerInterface $logger;

    public function __construct(Container $container, LoggerInterface $logger) {
        $this->container = $container;
        $this->logger = $logger;
    }

    public function handle(mixed $event): void
    {
        try {
            // FIX: Listener may run from HTTP request OR CLI/Job context.
            $correlationId = null;
            if (PHP_SAPI !== 'cli' && function_exists('app')) {
                try {
                    $correlationId = app()->request->header('x-request-id');
                } catch (\Throwable $e) {
                    \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.NotificationRequestListener.handle']);
                    $this->logger->warning('notificationrequestlistener.operation_failed', ['error' => $e->getMessage()]);
                }
            }

            $userId = null;
            $type = '';
            $title = '';
            $message = '';
            $data = [];
            $actionUrl = null;
            $actionText = null;
            $priority = 'normal';

            if ($event instanceof \App\Events\NotificationRequestedEvent) {
                $userId = $event->userId;
                $type = $event->type;
                $title = $event->title;
                $message = $event->message;
                $data = $event->data ?? [];
                $actionUrl = $event->actionUrl ?? null;
                $actionText = $event->actionText ?? null;
                $priority = $event->priority ?? 'normal';
            } elseif ($event instanceof \Core\Event) {
                $d = $event->getData();
                $d = is_array($d) ? $d : [];
                $userId = $d['user_id'] ?? ($d['recipient_id'] ?? null);
                $type = $d['type'] ?? 'system';
                $title = $d['title'] ?? '';
                $message = $d['message'] ?? '';
                $data = $d['data'] ?? [];
                $actionUrl = $d['action_url'] ?? null;
                $actionText = $d['action_text'] ?? null;
                $priority = $d['priority'] ?? 'normal';
            } elseif (is_array($event)) {
                $userId = $event['user_id'] ?? ($event['recipient_id'] ?? null);
                $type = $event['type'] ?? 'system';
                $title = $event['title'] ?? '';
                $message = $event['message'] ?? '';
                $data = $event['data'] ?? [];
                $actionUrl = $event['action_url'] ?? null;
                $actionText = $event['action_text'] ?? null;
                $priority = $event['priority'] ?? 'normal';
            } else {
                return;
            }

            if (empty($userId)) {
                $this->logger->warning('notification.request.missing_user', [
                    'event' => $event,
                    'correlation_id' => $correlationId
                ]);
                return;
            }

            // Inject correlation into data for tracing in notifications
            if (!is_array($data)) $data = [];
            if (empty($correlationId)) {
                $correlationId = $data['correlation_id'] ?? 'cli-' . bin2hex(random_bytes(4));
            }
            $data['correlation_id'] = $correlationId;

            /** @var \App\Services\Notification\NotificationService $notificationService */
            $notificationService = $this->container->make(\App\Services\Notification\NotificationService::class);
            $notificationService->send(
                (int)$userId,
                (string)$type,
                (string)$title,
                (string)$message,
                (array)$data,
                $actionUrl,
                $actionText,
                $priority
            );

            $this->logger->debug('notification.request.processed', [
                'user_id' => $userId,
                'type' => $type,
                'correlation_id' => $correlationId
            ]);

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.NotificationRequestListener.handle']);
            $this->logger->error('notification.request.listener_failed', [
                'error' => $e->getMessage(),
                'event' => $event,
                'correlation_id' => $correlationId ?? null
            ]);
        }
    }
}
