<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\Gamification\XpService;
use App\Services\ScoreService;
use App\Models\User;
use App\Enums\ModuleContext;

class GamificationTest {
    private Database $db;
    private XpService $xpService;
    private ScoreService $scoreService;
    private int $testUserId = 44444;

    public function __construct() {
        $this->db = Container::getInstance()->make(Database::class);
        $this->xpService = Container::getInstance()->make(XpService::class);
        $this->scoreService = Container::getInstance()->make(ScoreService::class);
        
        $this->setupTestData();
    }

    private function setupTestData(): void {
        $this->db->prepare("INSERT IGNORE INTO users (id, email, full_name, password, created_at) VALUES (?, 'game@test.com', 'Gamer', 'pass', NOW())")->execute([$this->testUserId]);
        
        // Clear Redis idempotency keys
        try {
            $cache = Container::getInstance()->make(\Core\Cache::class);
            $redis = $cache->redis();
            if ($redis) {
                $redis->del("score_idemp:test_xp_award");
                $redis->del("score_idemp:xp_global:44444:youtube_tasks:" . date('Y-m-d'));
            }
        } catch (\Throwable $e) {}

        // Reset XP and Score
        $this->db->prepare("DELETE FROM user_scores WHERE user_id = ?")->execute([$this->testUserId]);
        $this->db->prepare("DELETE FROM score_events WHERE entity_id = ? AND entity_type = 'user'")->execute([$this->testUserId]);
        $this->db->prepare("DELETE FROM influencer_profiles WHERE user_id = ?")->execute([$this->testUserId]);
    }

    public function run(): void {
        echo "--- Phase 4: Gamification & Scoring Integration Test ---\n";

        // 1. Test XP Award
        echo "Testing XP award... ";
        
        // Manual XP award via service
        $res = $this->xpService->award($this->testUserId, ModuleContext::YOUTUBE_TASKS, 10.0, "test_xp_award");
        echo "[award return: " . ($res ? "true" : "false") . "] ";
        
        $domain = \App\Enums\ScoreDomain::dynamicDomain(\App\Enums\ScoreDomain::PREFIX_XP, ModuleContext::YOUTUBE_TASKS);
        $currentXp = $this->db->fetchColumn("SELECT SUM(delta) FROM score_events WHERE entity_id = ? AND entity_type = 'user' AND domain = ?", [$this->testUserId, $domain]);
        if ((float)$currentXp < 10.0) {
            throw new Exception("XP not awarded. Current XP: " . ($currentXp ?? 'null'));
        }
        echo "XP: $currentXp (OK)\n";

        // 2. Test Reputation Score Delta
        echo "Testing reputation delta... ";
        // Using profile ID as the identifier for reputation in ScoreService
        // Let's create a profile for the user.
        $this->db->prepare("INSERT INTO influencer_profiles (user_id, username, platform, status) VALUES (?, 'gamer_inf', 'instagram', 'approved')")->execute([$this->testUserId]);
        $profile = $this->db->fetch("SELECT id FROM influencer_profiles WHERE user_id = ?", [$this->testUserId]);
        if ($profile === null) {
            throw new Exception("Influencer profile was not created");
        }
        $profileId = int_value($profile->id);

        $this->scoreService->applyDelta('profile', $profileId, 'reputation', 20, 'test_rep_increase');
        
        $repScore = $this->db->fetchColumn("SELECT SUM(delta) FROM score_events WHERE entity_id = ? AND entity_type = 'profile' AND domain = 'reputation'", [$profileId]);
        if ((float)$repScore < 20.0) {
            throw new Exception("Reputation not awarded. Current Rep: " . ($repScore ?? 'null'));
        }
        echo "Rep: $repScore (OK)\n";

        echo "\n✅ Gamification & Scoring Tests Passed!\n";
    }
}

try {
    $test = new GamificationTest();
    $test->run();
} catch (\Throwable $e) {
    echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
