<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The board's schema, in one piece.
 *
 * This replaces the incremental migrations written while the port was being built.
 * Nothing had been deployed from them, so there was no history worth preserving and
 * no old data to carry across. A new install creates the schema once, and reading
 * this tells you what the board looks like rather than how it got here.
 */
final class Version20260811050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the board schema.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE action_log (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(64) NOT NULL, target VARCHAR(128) DEFAULT NULL, context JSON DEFAULT \'{}\' NOT NULL, ip VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, actor_id INT DEFAULT NULL, INDEX idx_action_log_actor (actor_id), INDEX idx_action_log_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE announcements (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(250) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, edited_at DATETIME DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, tag_values JSON DEFAULT \'{}\' NOT NULL, author_id INT DEFAULT NULL, forum_id INT DEFAULT NULL, header_layout_id INT DEFAULT NULL, signature_layout_id INT DEFAULT NULL, INDEX IDX_F422A9DF675F31B (author_id), INDEX IDX_F422A9D29CCBAD0 (forum_id), INDEX IDX_F422A9D3C43EF39 (header_layout_id), INDEX IDX_F422A9D30F74586 (signature_layout_id), INDEX idx_announcement_forum (forum_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE board_config (id INT NOT NULL, board_name VARCHAR(100) DEFAULT \'Acmlm\'\'s Board\' NOT NULL, board_url VARCHAR(255) DEFAULT NULL, site_name VARCHAR(100) DEFAULT NULL, site_url VARCHAR(255) DEFAULT NULL, registration_policy INT DEFAULT 0 NOT NULL, registration_email VARCHAR(180) DEFAULT NULL, thread_locking_enabled TINYINT DEFAULT 0 NOT NULL, search_min_power INT DEFAULT 0 NOT NULL, forum_ban_min_power INT DEFAULT 2 NOT NULL, max_posts_per_thread_per_day INT DEFAULT 50 NOT NULL, custom_title_post_threshold INT DEFAULT 2000 NOT NULL, custom_title_age_post_threshold INT DEFAULT 1000 NOT NULL, custom_title_age_day_threshold INT DEFAULT 200 NOT NULL, prevent_double_posting TINYINT DEFAULT 1 NOT NULL, require_email_verification TINYINT DEFAULT 1 NOT NULL, default_timezone VARCHAR(64) DEFAULT \'UTC\' NOT NULL, passkeys_enabled TINYINT DEFAULT 1 NOT NULL, totp_enabled TINYINT DEFAULT 1 NOT NULL, deleted_user_account_id INT DEFAULT NULL, system_account_id INT DEFAULT NULL, disciplinary_forum_id INT DEFAULT NULL, trash_forum_id INT DEFAULT NULL, INDEX IDX_78946BDB1331D310 (deleted_user_account_id), INDEX IDX_78946BDB2EB84F83 (system_account_id), INDEX IDX_78946BDBD339220E (disciplinary_forum_id), INDEX IDX_78946BDBFD23759 (trash_forum_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE board_stats (id INT NOT NULL, page_views BIGINT DEFAULT 0 NOT NULL, hot_thread_threshold INT DEFAULT 30 NOT NULL, max_posts_in_day INT DEFAULT 0 NOT NULL, max_posts_in_day_at DATETIME DEFAULT NULL, max_posts_in_hour INT DEFAULT 0 NOT NULL, max_posts_in_hour_at DATETIME DEFAULT NULL, max_users_online INT DEFAULT 0 NOT NULL, max_users_online_at DATETIME DEFAULT NULL, max_users_online_names JSON DEFAULT \'[]\' NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE calendar_events (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, annual TINYINT DEFAULT 0 NOT NULL, title VARCHAR(200) NOT NULL, body LONGTEXT DEFAULT NULL, author_id INT DEFAULT NULL, INDEX IDX_F9E14F16F675F31B (author_id), INDEX idx_calendar_date (date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE categories (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, min_power INT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE color_schemes (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(50) NOT NULL, name VARCHAR(50) NOT NULL, position INT DEFAULT 0 NOT NULL, time_cycling TINYINT DEFAULT 0 NOT NULL, title_image VARCHAR(255) DEFAULT NULL, UNIQUE INDEX uniq_color_scheme_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE daily_stats (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, users INT DEFAULT 0 NOT NULL, threads INT DEFAULT 0 NOT NULL, posts INT DEFAULT 0 NOT NULL, views BIGINT DEFAULT 0 NOT NULL, UNIQUE INDEX uniq_daily_stat_date (date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE favorites (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, thread_id INT NOT NULL, INDEX IDX_E46960F5A76ED395 (user_id), INDEX IDX_E46960F5E2904019 (thread_id), UNIQUE INDEX uniq_favorite (user_id, thread_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE forums (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(250) NOT NULL, description LONGTEXT DEFAULT NULL, min_power INT DEFAULT 0 NOT NULL, min_power_thread INT DEFAULT 0 NOT NULL, min_power_reply INT DEFAULT 0 NOT NULL, thread_count INT DEFAULT 0 NOT NULL, post_count INT DEFAULT 0 NOT NULL, last_post_at DATETIME DEFAULT NULL, position INT DEFAULT 0 NOT NULL, trash TINYINT DEFAULT 0 NOT NULL, category_id INT NOT NULL, last_poster_id INT DEFAULT NULL, INDEX IDX_FE5E5AB84C79B303 (last_poster_id), INDEX idx_forum_category (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE forum_bans (id INT AUTO_INCREMENT NOT NULL, expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, reason LONGTEXT DEFAULT NULL, forum_id INT NOT NULL, user_id INT NOT NULL, issued_by_id INT DEFAULT NULL, INDEX IDX_8ED87FB29CCBAD0 (forum_id), INDEX IDX_8ED87FBA76ED395 (user_id), INDEX IDX_8ED87FB784BB717 (issued_by_id), INDEX idx_forum_ban_expiry (expires_at), UNIQUE INDEX uniq_forum_ban (forum_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE forum_moderators (id INT AUTO_INCREMENT NOT NULL, forum_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_DA3BAB9629CCBAD0 (forum_id), INDEX IDX_DA3BAB96A76ED395 (user_id), UNIQUE INDEX uniq_forum_moderator (forum_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE forum_reads (id INT AUTO_INCREMENT NOT NULL, read_at DATETIME NOT NULL, user_id INT NOT NULL, forum_id INT NOT NULL, INDEX IDX_D034CA66A76ED395 (user_id), INDEX IDX_D034CA6629CCBAD0 (forum_id), UNIQUE INDEX uniq_forum_read (user_id, forum_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE guest_sessions (ip VARCHAR(45) NOT NULL, last_seen_at DATETIME NOT NULL, last_url VARCHAR(255) DEFAULT NULL, current_forum_id INT DEFAULT NULL, INDEX idx_guest_seen (last_seen_at), INDEX idx_guest_forum (current_forum_id), PRIMARY KEY (ip)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE ip_bans (id INT AUTO_INCREMENT NOT NULL, ip_range VARCHAR(64) NOT NULL, reason VARCHAR(255) DEFAULT NULL, expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, issued_by_id INT DEFAULT NULL, INDEX IDX_D86B7BA3784BB717 (issued_by_id), UNIQUE INDEX uniq_ip_ban_range (ip_range), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE items (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, stats JSON DEFAULT \'{}\' NOT NULL, stat_modes JSON DEFAULT \'{}\' NOT NULL, price INT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, category_id INT DEFAULT NULL, INDEX idx_item_category (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE item_categories (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(40) NOT NULL, description VARCHAR(255) NOT NULL, position INT DEFAULT 0 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE passkeys (id INT AUTO_INCREMENT NOT NULL, credential_id VARCHAR(512) NOT NULL, credential_source LONGTEXT NOT NULL, name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, sign_count INT DEFAULT 0 NOT NULL, aaguid VARCHAR(64) DEFAULT NULL, backed_up TINYINT DEFAULT NULL, user_id INT NOT NULL, INDEX idx_passkey_user (user_id), UNIQUE INDEX uniq_passkey_credential (credential_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE pending_registrations (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(25) NOT NULL, username_canonical VARCHAR(25) NOT NULL, email VARCHAR(180) NOT NULL, code_hash VARCHAR(64) NOT NULL, ip VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, reminders_sent INT DEFAULT 0 NOT NULL, INDEX idx_pending_email (email), INDEX idx_pending_expiry (expires_at), UNIQUE INDEX uniq_pending_username (username_canonical), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE polls (id INT AUTO_INCREMENT NOT NULL, question VARCHAR(255) NOT NULL, briefing LONGTEXT DEFAULT NULL, closed TINYINT DEFAULT 0 NOT NULL, multi_vote TINYINT DEFAULT 0 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE poll_choices (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, color VARCHAR(7) DEFAULT NULL, vote_count INT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, poll_id INT DEFAULT NULL, INDEX idx_poll_choice_poll (poll_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE poll_votes (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, poll_id INT NOT NULL, choice_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_373A070E3C947C0F (poll_id), INDEX IDX_373A070E998666D1 (choice_id), INDEX IDX_373A070EA76ED395 (user_id), INDEX idx_poll_vote_poll_user (poll_id, user_id), UNIQUE INDEX uniq_poll_vote_choice (choice_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE posts (id INT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, ip VARCHAR(45) DEFAULT NULL, author_post_number INT DEFAULT 0 NOT NULL, tag_values JSON DEFAULT \'{}\' NOT NULL, edited_at DATETIME DEFAULT NULL, thread_id INT NOT NULL, author_id INT DEFAULT NULL, header_layout_id INT DEFAULT NULL, signature_layout_id INT DEFAULT NULL, edited_by_id INT DEFAULT NULL, INDEX IDX_885DBAFAE2904019 (thread_id), INDEX IDX_885DBAFA3C43EF39 (header_layout_id), INDEX IDX_885DBAFA30F74586 (signature_layout_id), INDEX IDX_885DBAFADD7B2EBC (edited_by_id), INDEX idx_post_thread (thread_id, id), INDEX idx_post_author (author_id), INDEX idx_post_created (created_at), INDEX idx_post_ip (ip), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE post_layouts (id INT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, body_hash VARCHAR(64) NOT NULL, UNIQUE INDEX uniq_post_layout_hash (body_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE private_messages (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, ip VARCHAR(45) DEFAULT NULL, read_at DATETIME DEFAULT NULL, recipient_folder INT DEFAULT 1 NOT NULL, sender_folder INT DEFAULT 2 NOT NULL, system TINYINT DEFAULT 0 NOT NULL, tag_values JSON DEFAULT \'{}\' NOT NULL, sender_id INT DEFAULT NULL, recipient_id INT NOT NULL, header_layout_id INT DEFAULT NULL, signature_layout_id INT DEFAULT NULL, INDEX IDX_7C94C13BF624B39D (sender_id), INDEX IDX_7C94C13BE92F8F78 (recipient_id), INDEX IDX_7C94C13B3C43EF39 (header_layout_id), INDEX IDX_7C94C13B30F74586 (signature_layout_id), INDEX idx_pm_recipient (recipient_id, recipient_folder, id), INDEX idx_pm_sender (sender_id, sender_folder, id), INDEX idx_pm_unread (recipient_id, read_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE private_message_folders (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, name VARCHAR(100) NOT NULL, user_id INT NOT NULL, INDEX IDX_2551065BA76ED395 (user_id), UNIQUE INDEX uniq_pm_folder_number (user_id, number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE punishments (id INT AUTO_INCREMENT NOT NULL, strikes INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, user_id INT NOT NULL, thread_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_4AABC577E2904019 (thread_id), UNIQUE INDEX uniq_punishment_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE ranks (id INT AUTO_INCREMENT NOT NULL, min_posts INT DEFAULT 0 NOT NULL, label LONGTEXT NOT NULL, percentile DOUBLE PRECISION DEFAULT NULL, rank_set_id INT NOT NULL, INDEX IDX_CBE6A0149E68E6AE (rank_set_id), INDEX idx_rank_lookup (rank_set_id, min_posts), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE rank_sets (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, position INT DEFAULT 0 NOT NULL, percentile_based TINYINT DEFAULT 0 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE rpg_profiles (id INT AUTO_INCREMENT NOT NULL, spent INT DEFAULT 0 NOT NULL, loadout JSON DEFAULT \'{}\' NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_3D444000A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE soft_bans (id INT AUTO_INCREMENT NOT NULL, expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, reason LONGTEXT DEFAULT NULL, user_id INT NOT NULL, issued_by_id INT DEFAULT NULL, INDEX IDX_E32CF8BA76ED395 (user_id), INDEX IDX_E32CF8B784BB717 (issued_by_id), INDEX idx_soft_ban_expiry (expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE threads (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(100) NOT NULL, icon VARCHAR(200) DEFAULT NULL, views INT DEFAULT 0 NOT NULL, replies INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, last_post_at DATETIME NOT NULL, closed TINYINT DEFAULT 0 NOT NULL, sticky TINYINT DEFAULT 0 NOT NULL, locked TINYINT DEFAULT 0 NOT NULL, forum_id INT NOT NULL, author_id INT DEFAULT NULL, last_poster_id INT DEFAULT NULL, poll_id INT DEFAULT NULL, INDEX IDX_6F8E3DDD29CCBAD0 (forum_id), INDEX IDX_6F8E3DDD4C79B303 (last_poster_id), UNIQUE INDEX UNIQ_6F8E3DDD3C947C0F (poll_id), INDEX idx_thread_forum_sort (forum_id, sticky, last_post_at), INDEX idx_thread_author (author_id), INDEX idx_thread_last_post (last_post_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE thread_layouts (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(50) NOT NULL, name VARCHAR(50) NOT NULL, position INT DEFAULT 0 NOT NULL, UNIQUE INDEX uniq_thread_layout_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(25) NOT NULL, username_canonical VARCHAR(25) NOT NULL, password VARCHAR(255) NOT NULL, password_legacy_md5 TINYINT DEFAULT 0 NOT NULL, email VARCHAR(180) DEFAULT NULL, email_public TINYINT DEFAULT 1 NOT NULL, power_level INT DEFAULT 0 NOT NULL, sex INT DEFAULT 2 NOT NULL, posts INT DEFAULT 0 NOT NULL, registered_at DATETIME NOT NULL, last_activity_at DATETIME DEFAULT NULL, last_post_at DATETIME DEFAULT NULL, last_ip VARCHAR(45) DEFAULT NULL, last_url VARCHAR(255) DEFAULT NULL, title LONGTEXT DEFAULT NULL, title_option INT DEFAULT 1 NOT NULL, picture VARCHAR(255) DEFAULT NULL, mini_pic VARCHAR(255) DEFAULT NULL, post_background VARCHAR(255) DEFAULT NULL, post_header LONGTEXT DEFAULT NULL, signature LONGTEXT DEFAULT NULL, bio LONGTEXT DEFAULT NULL, real_name VARCHAR(60) DEFAULT NULL, location VARCHAR(200) DEFAULT NULL, birthday DATE DEFAULT NULL, birthday_month_day INT DEFAULT NULL, homepage_url VARCHAR(255) DEFAULT NULL, homepage_name VARCHAR(100) DEFAULT NULL, posts_per_page INT DEFAULT 20 NOT NULL, threads_per_page INT DEFAULT 50 NOT NULL, timezone VARCHAR(64) DEFAULT \'UTC\' NOT NULL, signature_display INT DEFAULT 1 NOT NULL, signature_separator INT DEFAULT 0 NOT NULL, post_toolbar TINYINT DEFAULT 1 NOT NULL, mark_read_outside_menu TINYINT DEFAULT 0 NOT NULL, webauthn_handle VARCHAR(64) DEFAULT NULL, totp_secret VARCHAR(255) DEFAULT NULL, totp_confirmed_at DATETIME DEFAULT NULL, totp_recovery_codes JSON DEFAULT \'[]\' NOT NULL, current_forum_id INT DEFAULT NULL, rank_set_id INT DEFAULT NULL, color_scheme_id INT DEFAULT NULL, thread_layout_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_1483A5E9F85E0677 (username), UNIQUE INDEX UNIQ_1483A5E992FC23A8 (username_canonical), UNIQUE INDEX UNIQ_1483A5E9387A2A25 (webauthn_handle), INDEX IDX_1483A5E99E68E6AE (rank_set_id), INDEX IDX_1483A5E9FD77DD45 (color_scheme_id), INDEX IDX_1483A5E934885A6 (thread_layout_id), INDEX idx_user_posts (posts), INDEX idx_user_last_activity (last_activity_at), INDEX idx_user_last_post (last_post_at), INDEX idx_user_current_forum (current_forum_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE blocked_layouts (user_id INT NOT NULL, blocked_user_id INT NOT NULL, INDEX IDX_ECE21EC2A76ED395 (user_id), INDEX IDX_ECE21EC21EBCBB63 (blocked_user_id), PRIMARY KEY (user_id, blocked_user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE post_radar (user_id INT NOT NULL, rival_id INT NOT NULL, INDEX IDX_DC827131A76ED395 (user_id), INDEX IDX_DC8271314A0A40A (rival_id), PRIMARY KEY (user_id, rival_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE user_pictures (id INT AUTO_INCREMENT NOT NULL, url VARCHAR(255) NOT NULL, name VARCHAR(100) NOT NULL, category_id INT NOT NULL, INDEX idx_user_picture_category (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE user_picture_categories (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(200) NOT NULL, page INT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE user_ratings (id INT AUTO_INCREMENT NOT NULL, rating INT NOT NULL, updated_at DATETIME NOT NULL, rater_id INT NOT NULL, rated_id INT NOT NULL, INDEX IDX_96BB19C33FC1CD0A (rater_id), INDEX idx_user_rating_rated (rated_id), UNIQUE INDEX uniq_user_rating (rater_id, rated_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE rememberme_token (series VARCHAR(88) NOT NULL, value VARCHAR(88) NOT NULL, lastUsed DATETIME NOT NULL, class VARCHAR(100) DEFAULT \'\' NOT NULL, username VARCHAR(200) NOT NULL, PRIMARY KEY (series)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE action_log ADD CONSTRAINT FK_B2C5F68510DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE announcements ADD CONSTRAINT FK_F422A9DF675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE announcements ADD CONSTRAINT FK_F422A9D29CCBAD0 FOREIGN KEY (forum_id) REFERENCES forums (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE announcements ADD CONSTRAINT FK_F422A9D3C43EF39 FOREIGN KEY (header_layout_id) REFERENCES post_layouts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE announcements ADD CONSTRAINT FK_F422A9D30F74586 FOREIGN KEY (signature_layout_id) REFERENCES post_layouts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE board_config ADD CONSTRAINT FK_78946BDB1331D310 FOREIGN KEY (deleted_user_account_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE board_config ADD CONSTRAINT FK_78946BDB2EB84F83 FOREIGN KEY (system_account_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE board_config ADD CONSTRAINT FK_78946BDBD339220E FOREIGN KEY (disciplinary_forum_id) REFERENCES forums (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE board_config ADD CONSTRAINT FK_78946BDBFD23759 FOREIGN KEY (trash_forum_id) REFERENCES forums (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE calendar_events ADD CONSTRAINT FK_F9E14F16F675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE favorites ADD CONSTRAINT FK_E46960F5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE favorites ADD CONSTRAINT FK_E46960F5E2904019 FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forums ADD CONSTRAINT FK_FE5E5AB812469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE forums ADD CONSTRAINT FK_FE5E5AB84C79B303 FOREIGN KEY (last_poster_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE forum_bans ADD CONSTRAINT FK_8ED87FB29CCBAD0 FOREIGN KEY (forum_id) REFERENCES forums (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_bans ADD CONSTRAINT FK_8ED87FBA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_bans ADD CONSTRAINT FK_8ED87FB784BB717 FOREIGN KEY (issued_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE forum_moderators ADD CONSTRAINT FK_DA3BAB9629CCBAD0 FOREIGN KEY (forum_id) REFERENCES forums (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_moderators ADD CONSTRAINT FK_DA3BAB96A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_reads ADD CONSTRAINT FK_D034CA66A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_reads ADD CONSTRAINT FK_D034CA6629CCBAD0 FOREIGN KEY (forum_id) REFERENCES forums (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE guest_sessions ADD CONSTRAINT FK_E54A556CB4B4C0FA FOREIGN KEY (current_forum_id) REFERENCES forums (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ip_bans ADD CONSTRAINT FK_D86B7BA3784BB717 FOREIGN KEY (issued_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE items ADD CONSTRAINT FK_E11EE94D12469DE2 FOREIGN KEY (category_id) REFERENCES item_categories (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE passkeys ADD CONSTRAINT FK_42BD7728A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE poll_choices ADD CONSTRAINT FK_F99B1B063C947C0F FOREIGN KEY (poll_id) REFERENCES polls (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE poll_votes ADD CONSTRAINT FK_373A070E3C947C0F FOREIGN KEY (poll_id) REFERENCES polls (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE poll_votes ADD CONSTRAINT FK_373A070E998666D1 FOREIGN KEY (choice_id) REFERENCES poll_choices (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE poll_votes ADD CONSTRAINT FK_373A070EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE posts ADD CONSTRAINT FK_885DBAFAE2904019 FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE posts ADD CONSTRAINT FK_885DBAFAF675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE posts ADD CONSTRAINT FK_885DBAFA3C43EF39 FOREIGN KEY (header_layout_id) REFERENCES post_layouts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE posts ADD CONSTRAINT FK_885DBAFA30F74586 FOREIGN KEY (signature_layout_id) REFERENCES post_layouts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE posts ADD CONSTRAINT FK_885DBAFADD7B2EBC FOREIGN KEY (edited_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE private_messages ADD CONSTRAINT FK_7C94C13BF624B39D FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE private_messages ADD CONSTRAINT FK_7C94C13BE92F8F78 FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE private_messages ADD CONSTRAINT FK_7C94C13B3C43EF39 FOREIGN KEY (header_layout_id) REFERENCES post_layouts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE private_messages ADD CONSTRAINT FK_7C94C13B30F74586 FOREIGN KEY (signature_layout_id) REFERENCES post_layouts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE private_message_folders ADD CONSTRAINT FK_2551065BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE punishments ADD CONSTRAINT FK_4AABC577A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE punishments ADD CONSTRAINT FK_4AABC577E2904019 FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ranks ADD CONSTRAINT FK_CBE6A0149E68E6AE FOREIGN KEY (rank_set_id) REFERENCES rank_sets (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rpg_profiles ADD CONSTRAINT FK_3D444000A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE soft_bans ADD CONSTRAINT FK_E32CF8BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE soft_bans ADD CONSTRAINT FK_E32CF8B784BB717 FOREIGN KEY (issued_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE threads ADD CONSTRAINT FK_6F8E3DDD29CCBAD0 FOREIGN KEY (forum_id) REFERENCES forums (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE threads ADD CONSTRAINT FK_6F8E3DDDF675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE threads ADD CONSTRAINT FK_6F8E3DDD4C79B303 FOREIGN KEY (last_poster_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE threads ADD CONSTRAINT FK_6F8E3DDD3C947C0F FOREIGN KEY (poll_id) REFERENCES polls (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9B4B4C0FA FOREIGN KEY (current_forum_id) REFERENCES forums (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E99E68E6AE FOREIGN KEY (rank_set_id) REFERENCES rank_sets (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9FD77DD45 FOREIGN KEY (color_scheme_id) REFERENCES color_schemes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E934885A6 FOREIGN KEY (thread_layout_id) REFERENCES thread_layouts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE blocked_layouts ADD CONSTRAINT FK_ECE21EC2A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blocked_layouts ADD CONSTRAINT FK_ECE21EC21EBCBB63 FOREIGN KEY (blocked_user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post_radar ADD CONSTRAINT FK_DC827131A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post_radar ADD CONSTRAINT FK_DC8271314A0A40A FOREIGN KEY (rival_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_pictures ADD CONSTRAINT FK_6FF1CBC012469DE2 FOREIGN KEY (category_id) REFERENCES user_picture_categories (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_ratings ADD CONSTRAINT FK_96BB19C33FC1CD0A FOREIGN KEY (rater_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_ratings ADD CONSTRAINT FK_96BB19C34AB3C549 FOREIGN KEY (rated_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE action_log DROP FOREIGN KEY FK_B2C5F68510DAF24A');
        $this->addSql('ALTER TABLE announcements DROP FOREIGN KEY FK_F422A9DF675F31B');
        $this->addSql('ALTER TABLE announcements DROP FOREIGN KEY FK_F422A9D29CCBAD0');
        $this->addSql('ALTER TABLE announcements DROP FOREIGN KEY FK_F422A9D3C43EF39');
        $this->addSql('ALTER TABLE announcements DROP FOREIGN KEY FK_F422A9D30F74586');
        $this->addSql('ALTER TABLE board_config DROP FOREIGN KEY FK_78946BDB1331D310');
        $this->addSql('ALTER TABLE board_config DROP FOREIGN KEY FK_78946BDB2EB84F83');
        $this->addSql('ALTER TABLE board_config DROP FOREIGN KEY FK_78946BDBD339220E');
        $this->addSql('ALTER TABLE board_config DROP FOREIGN KEY FK_78946BDBFD23759');
        $this->addSql('ALTER TABLE calendar_events DROP FOREIGN KEY FK_F9E14F16F675F31B');
        $this->addSql('ALTER TABLE favorites DROP FOREIGN KEY FK_E46960F5A76ED395');
        $this->addSql('ALTER TABLE favorites DROP FOREIGN KEY FK_E46960F5E2904019');
        $this->addSql('ALTER TABLE forums DROP FOREIGN KEY FK_FE5E5AB812469DE2');
        $this->addSql('ALTER TABLE forums DROP FOREIGN KEY FK_FE5E5AB84C79B303');
        $this->addSql('ALTER TABLE forum_bans DROP FOREIGN KEY FK_8ED87FB29CCBAD0');
        $this->addSql('ALTER TABLE forum_bans DROP FOREIGN KEY FK_8ED87FBA76ED395');
        $this->addSql('ALTER TABLE forum_bans DROP FOREIGN KEY FK_8ED87FB784BB717');
        $this->addSql('ALTER TABLE forum_moderators DROP FOREIGN KEY FK_DA3BAB9629CCBAD0');
        $this->addSql('ALTER TABLE forum_moderators DROP FOREIGN KEY FK_DA3BAB96A76ED395');
        $this->addSql('ALTER TABLE forum_reads DROP FOREIGN KEY FK_D034CA66A76ED395');
        $this->addSql('ALTER TABLE forum_reads DROP FOREIGN KEY FK_D034CA6629CCBAD0');
        $this->addSql('ALTER TABLE guest_sessions DROP FOREIGN KEY FK_E54A556CB4B4C0FA');
        $this->addSql('ALTER TABLE ip_bans DROP FOREIGN KEY FK_D86B7BA3784BB717');
        $this->addSql('ALTER TABLE items DROP FOREIGN KEY FK_E11EE94D12469DE2');
        $this->addSql('ALTER TABLE passkeys DROP FOREIGN KEY FK_42BD7728A76ED395');
        $this->addSql('ALTER TABLE poll_choices DROP FOREIGN KEY FK_F99B1B063C947C0F');
        $this->addSql('ALTER TABLE poll_votes DROP FOREIGN KEY FK_373A070E3C947C0F');
        $this->addSql('ALTER TABLE poll_votes DROP FOREIGN KEY FK_373A070E998666D1');
        $this->addSql('ALTER TABLE poll_votes DROP FOREIGN KEY FK_373A070EA76ED395');
        $this->addSql('ALTER TABLE posts DROP FOREIGN KEY FK_885DBAFAE2904019');
        $this->addSql('ALTER TABLE posts DROP FOREIGN KEY FK_885DBAFAF675F31B');
        $this->addSql('ALTER TABLE posts DROP FOREIGN KEY FK_885DBAFA3C43EF39');
        $this->addSql('ALTER TABLE posts DROP FOREIGN KEY FK_885DBAFA30F74586');
        $this->addSql('ALTER TABLE posts DROP FOREIGN KEY FK_885DBAFADD7B2EBC');
        $this->addSql('ALTER TABLE private_messages DROP FOREIGN KEY FK_7C94C13BF624B39D');
        $this->addSql('ALTER TABLE private_messages DROP FOREIGN KEY FK_7C94C13BE92F8F78');
        $this->addSql('ALTER TABLE private_messages DROP FOREIGN KEY FK_7C94C13B3C43EF39');
        $this->addSql('ALTER TABLE private_messages DROP FOREIGN KEY FK_7C94C13B30F74586');
        $this->addSql('ALTER TABLE private_message_folders DROP FOREIGN KEY FK_2551065BA76ED395');
        $this->addSql('ALTER TABLE punishments DROP FOREIGN KEY FK_4AABC577A76ED395');
        $this->addSql('ALTER TABLE punishments DROP FOREIGN KEY FK_4AABC577E2904019');
        $this->addSql('ALTER TABLE ranks DROP FOREIGN KEY FK_CBE6A0149E68E6AE');
        $this->addSql('ALTER TABLE rpg_profiles DROP FOREIGN KEY FK_3D444000A76ED395');
        $this->addSql('ALTER TABLE soft_bans DROP FOREIGN KEY FK_E32CF8BA76ED395');
        $this->addSql('ALTER TABLE soft_bans DROP FOREIGN KEY FK_E32CF8B784BB717');
        $this->addSql('ALTER TABLE threads DROP FOREIGN KEY FK_6F8E3DDD29CCBAD0');
        $this->addSql('ALTER TABLE threads DROP FOREIGN KEY FK_6F8E3DDDF675F31B');
        $this->addSql('ALTER TABLE threads DROP FOREIGN KEY FK_6F8E3DDD4C79B303');
        $this->addSql('ALTER TABLE threads DROP FOREIGN KEY FK_6F8E3DDD3C947C0F');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E9B4B4C0FA');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E99E68E6AE');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E9FD77DD45');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E934885A6');
        $this->addSql('ALTER TABLE blocked_layouts DROP FOREIGN KEY FK_ECE21EC2A76ED395');
        $this->addSql('ALTER TABLE blocked_layouts DROP FOREIGN KEY FK_ECE21EC21EBCBB63');
        $this->addSql('ALTER TABLE post_radar DROP FOREIGN KEY FK_DC827131A76ED395');
        $this->addSql('ALTER TABLE post_radar DROP FOREIGN KEY FK_DC8271314A0A40A');
        $this->addSql('ALTER TABLE user_pictures DROP FOREIGN KEY FK_6FF1CBC012469DE2');
        $this->addSql('ALTER TABLE user_ratings DROP FOREIGN KEY FK_96BB19C33FC1CD0A');
        $this->addSql('ALTER TABLE user_ratings DROP FOREIGN KEY FK_96BB19C34AB3C549');
        $this->addSql('DROP TABLE action_log');
        $this->addSql('DROP TABLE announcements');
        $this->addSql('DROP TABLE board_config');
        $this->addSql('DROP TABLE board_stats');
        $this->addSql('DROP TABLE calendar_events');
        $this->addSql('DROP TABLE categories');
        $this->addSql('DROP TABLE color_schemes');
        $this->addSql('DROP TABLE daily_stats');
        $this->addSql('DROP TABLE favorites');
        $this->addSql('DROP TABLE forums');
        $this->addSql('DROP TABLE forum_bans');
        $this->addSql('DROP TABLE forum_moderators');
        $this->addSql('DROP TABLE forum_reads');
        $this->addSql('DROP TABLE guest_sessions');
        $this->addSql('DROP TABLE ip_bans');
        $this->addSql('DROP TABLE items');
        $this->addSql('DROP TABLE item_categories');
        $this->addSql('DROP TABLE passkeys');
        $this->addSql('DROP TABLE pending_registrations');
        $this->addSql('DROP TABLE polls');
        $this->addSql('DROP TABLE poll_choices');
        $this->addSql('DROP TABLE poll_votes');
        $this->addSql('DROP TABLE posts');
        $this->addSql('DROP TABLE post_layouts');
        $this->addSql('DROP TABLE private_messages');
        $this->addSql('DROP TABLE private_message_folders');
        $this->addSql('DROP TABLE punishments');
        $this->addSql('DROP TABLE ranks');
        $this->addSql('DROP TABLE rank_sets');
        $this->addSql('DROP TABLE rpg_profiles');
        $this->addSql('DROP TABLE soft_bans');
        $this->addSql('DROP TABLE threads');
        $this->addSql('DROP TABLE thread_layouts');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE blocked_layouts');
        $this->addSql('DROP TABLE post_radar');
        $this->addSql('DROP TABLE user_pictures');
        $this->addSql('DROP TABLE user_picture_categories');
        $this->addSql('DROP TABLE user_ratings');
        $this->addSql('DROP TABLE rememberme_token');
    }
}
