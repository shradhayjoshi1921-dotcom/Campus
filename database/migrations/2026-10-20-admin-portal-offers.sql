-- Admin portal: offer and joining tracking.
-- Safe to run more than once. Existing tables and data are untouched.
-- Admin actions: import offers through the Students -> Placements section.
CREATE TABLE IF NOT EXISTS offers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    ctc_lpa DECIMAL(6,2) NULL,
    offer_date DATE NULL,
    status ENUM('offered','accepted','declined','joined','revoked') NOT NULL DEFAULT 'offered',
    joined_on DATE NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_offer_application (application_id),
    INDEX idx_offer_status (status),
    CONSTRAINT fk_offer_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_offer_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
