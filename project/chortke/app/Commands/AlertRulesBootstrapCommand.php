<?php

declare(strict_types=1);

namespace App\Commands;

use Core\Command;
use Core\Database;
use Core\Logger;

/**
 * 🚀 AlertRulesBootstrapCommand - رجسٹر کردن DLQ Alert Rule
 * 
 * این کمند DLQ alert rule را درج می‌کند:
 * - failed_jobs > 50 => CRITICAL alert
 * - failed_jobs > 20 => WARNING alert
 */
class AlertRulesBootstrapCommand extends Command
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db, Logger $logger) {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function handle(): void
    {
        $this->registerDLQAlertRules();
        $this->registerDatabaseAlertRules();
        $this->registerFinancialAnomalyRules();
        $this->registerSecurityRules();
        $this->registerSagaRules();
        $this->registerFraudRules();
        $this->line('✅ DLQ + Database + Financial + Security + Saga + Fraud Alert Rules Registered');
    }

    private function registerDLQAlertRules(): void
    {
        // چک کنید آیا rule وجود دارد
        $existing = $this->db->fetchColumn(
            "SELECT id FROM alert_rules WHERE rule_name = ?",
            ['DLQ Failed Jobs Critical']
        );

        if ($existing) {
            return; // Rule تالاً موجود است
        }

        // Critical Rule: failed_jobs > 50
        $this->db->table('alert_rules')->insert([
            'rule_name' => 'DLQ Failed Jobs Critical',
            'rule_type' => 'queue_dlq',
            'condition' => json_encode([
                'metric' => 'failed_jobs',
                'operator' => '>',
            ], JSON_UNESCAPED_UNICODE),
            'threshold' => 50,
            'severity' => 'critical',
            'time_window' => 5, // 5 minutes
            'is_active' => 1,
            'description' => 'تعداد failed jobs بیش از 50 است',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Warning Rule: failed_jobs > 20
        $this->db->table('alert_rules')->insert([
            'rule_name' => 'DLQ Failed Jobs Warning',
            'rule_type' => 'queue_dlq',
            'condition' => json_encode([
                'metric' => 'failed_jobs',
                'operator' => '>',
            ], JSON_UNESCAPED_UNICODE),
            'threshold' => 20,
            'severity' => 'warning',
            'time_window' => 5,
            'is_active' => 1,
            'description' => 'تعداد failed jobs بیش از 20 است',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('alert_rules.dlq_bootstrap', [
            'message' => 'DLQ alert rules registered',
            'critical_threshold' => 50,
            'warning_threshold' => 20,
        ]);
    }

    private function registerDatabaseAlertRules(): void
    {
        $existing = $this->db->fetchColumn(
            "SELECT id FROM alert_rules WHERE rule_name = ?",
            ['DB Slow Queries Critical']
        );
        if ($existing) return;

        // Critical: slow queries > 50
        $this->db->table('alert_rules')->insert([
            'rule_name'   => 'DB Slow Queries Critical',
            'rule_type'   => 'database_performance',
            'condition'   => json_encode(['metric' => 'slow_queries', 'operator' => '>'], JSON_UNESCAPED_UNICODE),
            'threshold'   => 50,
            'severity'    => 'critical',
            'time_window' => 15,
            'is_active'   => 0,
            'description' => 'تعداد کوئری‌های کند دیتابیس بیش از ۵۰ مورد',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        // Warning: slow queries > 20
        $this->db->table('alert_rules')->insert([
            'rule_name'   => 'DB Slow Queries Warning',
            'rule_type'   => 'database_performance',
            'condition'   => json_encode(['metric' => 'slow_queries', 'operator' => '>'], JSON_UNESCAPED_UNICODE),
            'threshold'   => 20,
            'severity'    => 'warning',
            'time_window' => 15,
            'is_active'   => 0,
            'description' => 'تعداد کوئری‌های کند دیتابیس بیش از ۲۰ مورد',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        // Warning: deadlocks > 0
        $this->db->table('alert_rules')->insert([
            'rule_name'   => 'DB Deadlocks Detected',
            'rule_type'   => 'database_performance',
            'condition'   => json_encode(['metric' => 'deadlocks', 'operator' => '>'], JSON_UNESCAPED_UNICODE),
            'threshold'   => 0,
            'severity'    => 'warning',
            'time_window' => 10,
            'is_active'   => 0,
            'description' => 'بن‌بست (Deadlock) در دیتابیس شناسایی شد',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        // Warning: missing indexes
        $this->db->table('alert_rules')->insert([
            'rule_name'   => 'DB Missing Index Recommendations',
            'rule_type'   => 'database_performance',
            'condition'   => json_encode(['metric' => 'missing_indexes', 'operator' => '>'], JSON_UNESCAPED_UNICODE),
            'threshold'   => 5,
            'severity'    => 'info',
            'time_window' => 1440,
            'is_active'   => 0,
            'description' => 'بیش از ۵ جدول نیاز به ایندکس جدید دارند',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('alert_rules.database_bootstrap', [
            'message' => 'Database alert rules registered (inactive by default)',
        ]);
    }

    /**
     * 💸 رول‌های ناهنجاری مالی — wallet/ledger mismatch + payment failures
     */
    private function registerFinancialAnomalyRules(): void
    {
        $rules = [
            [
                'rule_name'   => 'Wallet Ledger Mismatch Critical',
                'rule_type'   => 'wallet_anomalies',
                'metric'      => 'wallet_anomalies',
                'threshold'   => 1,
                'severity'    => 'critical',
                'time_window' => 10,
                'description' => 'عدم تطابق موجودی wallet با ledger شناسایی شد',
            ],
            [
                'rule_name'   => 'Payment Failures High',
                'rule_type'   => 'payment_failures',
                'metric'      => 'payment_failures',
                'threshold'   => 10,
                'severity'    => 'high',
                'time_window' => 15,
                'description' => 'بیش از ۱۰ پرداخت ناموفق در ۱۵ دقیقه اخیر',
            ],
            [
                'rule_name'   => 'Payment Failures Warning',
                'rule_type'   => 'payment_failures',
                'metric'      => 'payment_failures',
                'threshold'   => 5,
                'severity'    => 'warning',
                'time_window' => 15,
                'description' => 'بیش از ۵ پرداخت ناموفق در ۱۵ دقیقه اخیر',
            ],
        ];

        foreach ($rules as $rule) {
            $existing = $this->db->fetchColumn(
                "SELECT id FROM alert_rules WHERE rule_name = ?",
                [$rule['rule_name']]
            );
            if ($existing) continue;

            $this->db->table('alert_rules')->insert([
                'rule_name'   => $rule['rule_name'],
                'rule_type'   => $rule['rule_type'],
                'condition'   => json_encode(['metric' => $rule['metric'], 'operator' => '>'], JSON_UNESCAPED_UNICODE),
                'threshold'   => $rule['threshold'],
                'severity'    => $rule['severity'],
                'time_window' => $rule['time_window'],
                'is_active'   => 1,
                'description' => $rule['description'],
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        $this->logger->info('alert_rules.financial_bootstrap', ['message' => 'Financial anomaly alert rules registered']);
    }

    /**
     * 🔒 رول‌های امنیتی — login failures + suspicious IPs
     */
    private function registerSecurityRules(): void
    {
        $rules = [
            [
                'rule_name'   => 'Brute Force Login Critical',
                'rule_type'   => 'failed_login',
                'metric'      => 'failed_login',
                'threshold'   => 50,
                'severity'    => 'critical',
                'time_window' => 5,
                'description' => 'بیش از ۵۰ تلاش ناموفق ورود در ۵ دقیقه — احتمال Brute Force',
            ],
            [
                'rule_name'   => 'Brute Force Login Warning',
                'rule_type'   => 'failed_login',
                'metric'      => 'failed_login',
                'threshold'   => 20,
                'severity'    => 'warning',
                'time_window' => 5,
                'description' => 'بیش از ۲۰ تلاش ناموفق ورود در ۵ دقیقه',
            ],
            [
                'rule_name'   => 'Suspicious IPs Alert',
                'rule_type'   => 'suspicious_ips',
                'metric'      => 'suspicious_ips',
                'threshold'   => 5,
                'severity'    => 'high',
                'time_window' => 15,
                'description' => 'بیش از ۵ IP مشکوک (Tor/VPN/Proxy) در ۱۵ دقیقه',
            ],
        ];

        foreach ($rules as $rule) {
            $existing = $this->db->fetchColumn(
                "SELECT id FROM alert_rules WHERE rule_name = ?",
                [$rule['rule_name']]
            );
            if ($existing) continue;

            $this->db->table('alert_rules')->insert([
                'rule_name'   => $rule['rule_name'],
                'rule_type'   => $rule['rule_type'],
                'condition'   => json_encode(['metric' => $rule['metric'], 'operator' => '>'], JSON_UNESCAPED_UNICODE),
                'threshold'   => $rule['threshold'],
                'severity'    => $rule['severity'],
                'time_window' => $rule['time_window'],
                'is_active'   => 1,
                'description' => $rule['description'],
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        $this->logger->info('alert_rules.security_bootstrap', ['message' => 'Security alert rules registered']);
    }

    /**
     * 🔄 رول‌های Saga — stuck sagas
     */
    private function registerSagaRules(): void
    {
        $existing = $this->db->fetchColumn(
            "SELECT id FROM alert_rules WHERE rule_name = ?",
            ['Stuck Sagas Critical']
        );
        if ($existing) return;

        $this->db->table('alert_rules')->insert([
            'rule_name'   => 'Stuck Sagas Critical',
            'rule_type'   => 'stuck_sagas',
            'condition'   => json_encode(['metric' => 'stuck_sagas', 'operator' => '>'], JSON_UNESCAPED_UNICODE),
            'threshold'   => 0,
            'severity'    => 'critical',
            'time_window' => 30,
            'is_active'   => 1,
            'description' => 'Saga(های) گیر کرده بیش از ۳۰ دقیقه — نیاز به بررسی فوری',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('alert_rules.saga_bootstrap', ['message' => 'Saga stuck alert rules registered']);
    }

    /**
     * 🚨 رول‌های تشخیص تقلب
     */
    private function registerFraudRules(): void
    {
        $existing = $this->db->fetchColumn(
            "SELECT id FROM alert_rules WHERE rule_name = ?",
            ['High Risk Users Spike']
        );
        if ($existing) return;

        $this->db->table('alert_rules')->insert([
            'rule_name'   => 'High Risk Users Spike',
            'rule_type'   => 'fraud_score_high',
            'condition'   => json_encode(['metric' => 'fraud_score_high', 'operator' => '>'], JSON_UNESCAPED_UNICODE),
            'threshold'   => 10,
            'severity'    => 'high',
            'time_window' => 60,
            'is_active'   => 1,
            'description' => 'بیش از ۱۰ کاربر با امتیاز ریسک بالا (≥۷۵) در یک ساعت اخیر',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('alert_rules.fraud_bootstrap', ['message' => 'Fraud detection alert rules registered']);
    }

    /** @param array<string, mixed> $args */

    public function run(array $args = []): void
    {
        $this->handle();
    }
}
