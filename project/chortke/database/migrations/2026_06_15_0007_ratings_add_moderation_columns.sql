SET FOREIGN_KEY_CHECKS = 0;

-- Migration: Add moderation columns to ratings table for social task review workflow
-- Fixes: RatingService moderateReview no-op due to missing schema columns

ALTER TABLE ratings
    ADD COLUMN ad_id INT(10) UNSIGNED NULL AFTER target_id,
    ADD COLUMN ad_type VARCHAR(50) NULL AFTER ad_id,
    ADD COLUMN status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' AFTER comment,
    ADD COLUMN moderated_by INT(10) UNSIGNED NULL AFTER status,
    ADD COLUMN moderated_at TIMESTAMP NULL AFTER moderated_by,
    ADD INDEX idx_ratings_ad (ad_id, ad_type),
    ADD INDEX idx_ratings_status (status),
    ADD INDEX idx_ratings_moderated (moderated_by, moderated_at);

-- Backfill existing ratings: mark all existing as approved (retroactive compatibility)
UPDATE ratings SET status = 'approved', moderated_at = created_at WHERE status IS NULL OR status = 'pending';

SET FOREIGN_KEY_CHECKS = 1;
