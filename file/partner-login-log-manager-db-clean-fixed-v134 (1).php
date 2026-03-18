<?php
/**
 * Plugin Name: Partner Login Log Manager
 * Description: Legge gli eventi partner dal file log scelto, li sincronizza in una tabella DB per sintesi giornaliera corretta, filtri, paginazione e sblocco IP.
 * Version: 1.3.3
 * Author: OpenAI
 */

if (!defined('ABSPATH')) {
	exit;
}

class Partner_Login_Log_Manager {
	const OPTION_LOG_PATH = 'pllmg_log_path';
	const OPTION_MAX_LINES = 'pllmg_max_lines';
	const OPTION_SYNC_LINES = 'pllmg_sync_lines';
	const TABLE = 'pllmg_events';
	const SUMMARY_PER_PAGE = 50;
	const RAW_PER_PAGE_DEFAULT = 100;
	const BANS_PER_PAGE = 50;

	private $table_name = '';

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . self::TABLE;

		register_activation_hook(__FILE__, [$this, 'activate']);

		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_post_pllmg_unlock_ip', [$this, 'handle_unlock_ip']);
		add_action('admin_post_pllmg_block_ip', [$this, 'handle_block_ip']);
		add_action('admin_post_pllmg_save_settings', [$this, 'handle_save_settings']);
		add_action('admin_post_pllmg_sync_db', [$this, 'handle_sync_db']);
		add_action('admin_post_pllmg_clear_db', [$this, 'handle_clear_db']);
	}

	public function activate() {
		$this->create_table();
	}

	public function admin_menu() {
		add_menu_page(
			'Partner Login Log',
			'Partner Login Log',
			'manage_options',
			'partner-login-log-manager',
			[$this, 'render_page'],
			'dashicons-shield-alt',
			58
		);
	}

	public function register_settings() {
		if (get_option(self::OPTION_MAX_LINES) === false) {
			update_option(self::OPTION_MAX_LINES, self::RAW_PER_PAGE_DEFAULT);
		}
		if (get_option(self::OPTION_SYNC_LINES) === false) {
			update_option(self::OPTION_SYNC_LINES, 5000);
		}
		$this->create_table();
	}

	private function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$this->table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_hash CHAR(32) NOT NULL,
			event_time DATETIME NOT NULL,
			event_date DATE NOT NULL,
			event_type VARCHAR(20) NOT NULL,
			partner_id VARCHAR(191) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			ip VARCHAR(191) NOT NULL DEFAULT '',
			reason TEXT NULL,
			duration VARCHAR(191) NOT NULL DEFAULT '',
			user_agent TEXT NULL,
			raw_line LONGTEXT NULL,
			source_file VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY event_hash (event_hash),
			KEY event_date (event_date),
			KEY event_type (event_type),
			KEY partner_id (partner_id(100)),
			KEY email (email(100)),
			KEY ip (ip(100)),
			KEY event_time (event_time)
		) $charset_collate;";
		dbDelta($sql);
	}

	private function default_log_paths(): array {
		return [
			'/home2/vsosmedo/error_log_php',
			'/home2/vsosmedo/public_html/error_log_php',
			'/home2/vsosmedo/cafsanitario.sosmedico.org/error_log',
			'/home2/vsosmedo/error_log',
			'/home2/vsosmedo/public_html/error_log',
			ABSPATH . 'error_log',
			dirname(ABSPATH) . '/error_log',
			WP_CONTENT_DIR . '/debug.log',
		];
	}

	private function get_log_path(): string {
		$saved = get_option(self::OPTION_LOG_PATH, '');
		if (!empty($saved)) {
			return $saved;
		}
		foreach ($this->default_log_paths() as $path) {
			if (file_exists($path) && is_readable($path)) {
				return $path;
			}
		}
		return '';
	}

	private function get_max_lines(): int {
		return max(25, min(1000, (int) get_option(self::OPTION_MAX_LINES, self::RAW_PER_PAGE_DEFAULT)));
	}

	private function get_sync_lines(): int {
		return max(100, min(20000, (int) get_option(self::OPTION_SYNC_LINES, 5000)));
	}

	private function can_manage(): bool {
		return current_user_can('manage_options');
	}

	private function is_clean_partner_line(string $line): bool {
		$line = trim($line);
		if ($line === '') {
			return false;
		}

		if (stripos($line, 'PARTNER LOGIN') === false) {
			return false;
		}

		if (!preg_match('/PARTNER LOGIN (OK|FAIL|BAN|BLOCKED|MANUAL UNLOCK)\b/i', $line)) {
			return false;
		}

		$noise_markers = [
			'WordPress database error',
			'Duplicate entry',
			'INSERT INTO',
			'WP_Hook',
			'wpdb',
			'made by',
			'Warning:',
			'Notice:',
			'Fatal error:',
			'Stack trace',
			'query INSERT',
			'pllmg_events',
		];

		foreach ($noise_markers as $marker) {
			if (stripos($line, $marker) !== false) {
				return false;
			}
		}

		return true;
	}

	private function normalize_datetime(string $date): string {
		$date = trim($date);
		if ($date === '') {
			return current_time('mysql', true);
		}

		$ts = strtotime($date);
		if ($ts !== false) {
			return gmdate('Y-m-d H:i:s', $ts);
		}

		return current_time('mysql', true);
	}

	private function parse_log_line(string $line): ?array {
		$line = trim($line);
		if (!$this->is_clean_partner_line($line)) {
			return null;
		}

		$date = '';
		if (preg_match('/^\[(.*?)\]\s+PARTNER LOGIN /', $line, $matches)) {
			$date = trim($matches[1]);
		} elseif (preg_match('/^(\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2}\s+UTC)\s+PARTNER LOGIN /', $line, $matches)) {
			$date = trim($matches[1]);
		} elseif (preg_match('/(\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2}\s+UTC)/', $line, $matches)) {
			$date = trim($matches[1]);
		}

		$type = 'INFO';
		if (stripos($line, 'PARTNER LOGIN OK') !== false) {
			$type = 'OK';
		} elseif (stripos($line, 'PARTNER LOGIN FAIL') !== false) {
			$type = 'FAIL';
		} elseif (stripos($line, 'PARTNER LOGIN BAN') !== false) {
			$type = 'BAN';
		} elseif (stripos($line, 'PARTNER LOGIN BLOCKED') !== false) {
			$type = 'BLOCKED';
		} elseif (stripos($line, 'PARTNER LOGIN MANUAL UNLOCK') !== false) {
			$type = 'UNLOCK';
		}

		$partner = '';
		if (preg_match('/\|\s*partner:\s*([^|]+)/i', $line, $matches)) {
			$partner = trim($matches[1]);
		}

		$email = '';
		if (preg_match('/\|\s*email:\s*([^|]+)/i', $line, $matches)) {
			$email = trim($matches[1]);
		}

		$ip = '';
		if (preg_match('/\|\s*IP:\s*([^|]+)/i', $line, $matches)) {
			$ip = trim($matches[1]);
		}

		$ua = '';
		if (preg_match('/\|\s*UA:\s*(.+)$/i', $line, $matches)) {
			$ua = trim($matches[1]);
		}

		$reason = '';
		if (preg_match('/\|\s*motivo:\s*([^|]+(?:\|[^|]+)*)/i', $line, $matches)) {
			$reason = trim($matches[1]);
			$reason = preg_replace('/\s*\|\s*durata:.*$/i', '', $reason);
			$reason = preg_replace('/\s*\|\s*UA:.*$/i', '', $reason);
		}

		$duration = '';
		if (preg_match('/\|\s*durata:\s*([^|]+)/i', $line, $matches)) {
			$duration = trim($matches[1]);
		}

		$event_time = $this->normalize_datetime($date);

		return [
			'raw' => $line,
			'hash' => md5($line),
			'date' => $date,
			'date_key' => gmdate('Y-m-d', strtotime($event_time . ' UTC')),
			'timestamp' => strtotime($event_time . ' UTC') ?: 0,
			'event_time' => $event_time,
			'type' => $type,
			'partner' => $partner,
			'email' => $email,
			'ip' => $ip,
			'reason' => $reason,
			'duration' => $duration,
			'ua' => $ua,
		];
	}

	private function read_last_lines(string $file, int $max_lines = 300): array {
		if (!file_exists($file) || !is_readable($file)) {
			return [];
		}
		$lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines === false) {
			return [];
		}
		return array_slice($lines, -1 * $max_lines);
	}

	private function sync_db_from_log(): array {
		global $wpdb;

		$path = $this->get_log_path();
		$stats = ['inserted' => 0, 'skipped_dirty' => 0, 'skipped_duplicate' => 0];

		if (empty($path)) {
			return $stats;
		}

		$lines = $this->read_last_lines($path, $this->get_sync_lines());

		foreach ($lines as $line) {
			$line = trim((string) $line);
			if ($line === '') {
				continue;
			}

			if (!$this->is_clean_partner_line($line)) {
				if (stripos($line, 'PARTNER LOGIN') !== false) {
					$stats['skipped_dirty']++;
				}
				continue;
			}

			$parsed = $this->parse_log_line($line);
			if ($parsed === null) {
				$stats['skipped_dirty']++;
				continue;
			}

			$exists = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE event_hash = %s",
				$parsed['hash']
			));

			if ($exists > 0) {
				$stats['skipped_duplicate']++;
				continue;
			}

			$inserted = $wpdb->insert(
				$this->table_name,
				[
					'event_hash' => $parsed['hash'],
					'event_time' => $parsed['event_time'],
					'event_date' => $parsed['date_key'],
					'event_type' => $parsed['type'],
					'partner_id' => $parsed['partner'],
					'email' => $parsed['email'],
					'ip' => $parsed['ip'],
					'reason' => $parsed['reason'],
					'duration' => $parsed['duration'],
					'user_agent' => $parsed['ua'],
					'raw_line' => $parsed['raw'],
					'source_file' => $path,
					'created_at' => current_time('mysql', true),
				],
				['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']
			);

			if ($inserted) {
				$stats['inserted']++;
			}
		}

		return $stats;
	}

	private function get_filters(): array {
		return [
			'date_from' => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
			'date_to' => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
			'event_type' => isset($_GET['event_type']) ? sanitize_text_field(wp_unslash($_GET['event_type'])) : '',
			'partner' => isset($_GET['partner']) ? sanitize_text_field(wp_unslash($_GET['partner'])) : '',
			'email' => isset($_GET['email']) ? sanitize_text_field(wp_unslash($_GET['email'])) : '',
			'ip' => isset($_GET['ip']) ? sanitize_text_field(wp_unslash($_GET['ip'])) : '',
		];
	}

	private function build_where_sql(array $filters, array &$params): string {
		$where = ['1=1'];

		if ($filters['date_from'] !== '') {
			$where[] = 'event_date >= %s';
			$params[] = $filters['date_from'];
		}
		if ($filters['date_to'] !== '') {
			$where[] = 'event_date <= %s';
			$params[] = $filters['date_to'];
		}
		if ($filters['event_type'] !== '') {
			$where[] = 'event_type = %s';
			$params[] = strtoupper($filters['event_type']);
		}
		if ($filters['partner'] !== '') {
			$where[] = 'partner_id LIKE %s';
			$params[] = '%' . $filters['partner'] . '%';
		}
		if ($filters['email'] !== '') {
			$where[] = 'email LIKE %s';
			$params[] = '%' . $filters['email'] . '%';
		}
		if ($filters['ip'] !== '') {
			$where[] = 'ip LIKE %s';
			$params[] = '%' . $filters['ip'] . '%';
		}

		return implode(' AND ', $where);
	}

	private function query_summary(array $filters, int $page): array {
		global $wpdb;

		$params = [];
		$where = $this->build_where_sql($filters, $params);
		$offset = max(0, ($page - 1) * self::SUMMARY_PER_PAGE);

		$count_sql = "SELECT COUNT(*) FROM (
			SELECT event_date, partner_id, email
			FROM {$this->table_name}
			WHERE {$where}
			GROUP BY event_date, partner_id, email
		) t";
		$total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

		$sql = "SELECT
				event_date,
				partner_id,
				email,
				MIN(CASE WHEN event_type='OK' THEN event_time END) AS first_ok_at,
				MAX(CASE WHEN event_type='OK' THEN event_time END) AS last_ok_at,
				SUM(CASE WHEN event_type='OK' THEN 1 ELSE 0 END) AS ok_count,
				SUM(CASE WHEN event_type='FAIL' THEN 1 ELSE 0 END) AS fail_count,
				SUM(CASE WHEN event_type='BAN' OR event_type='BLOCKED' THEN 1 ELSE 0 END) AS ban_blocked_count,
				MAX(event_time) AS latest_event_time
			FROM {$this->table_name}
			WHERE {$where}
			GROUP BY event_date, partner_id, email
			ORDER BY event_date DESC, latest_event_time DESC
			LIMIT %d OFFSET %d";

		$params_with_limit = $params;
		$params_with_limit[] = self::SUMMARY_PER_PAGE;
		$params_with_limit[] = $offset;

		$rows = $wpdb->get_results($wpdb->prepare($sql, $params_with_limit), ARRAY_A);

		foreach ($rows as &$row) {
			$row['last_ip'] = (string) $wpdb->get_var($wpdb->prepare(
				"SELECT ip FROM {$this->table_name}
				 WHERE event_date=%s AND partner_id=%s AND email=%s
				 ORDER BY event_time DESC, id DESC LIMIT 1",
				$row['event_date'], $row['partner_id'], $row['email']
			));
			$row['last_user_agent'] = (string) $wpdb->get_var($wpdb->prepare(
				"SELECT user_agent FROM {$this->table_name}
				 WHERE event_date=%s AND partner_id=%s AND email=%s
				 ORDER BY event_time DESC, id DESC LIMIT 1",
				$row['event_date'], $row['partner_id'], $row['email']
			));
			$row['details'] = $wpdb->get_results($wpdb->prepare(
				"SELECT event_time, event_type, ip, reason
				 FROM {$this->table_name}
				 WHERE event_date=%s AND partner_id=%s AND email=%s
				 ORDER BY event_time DESC, id DESC
				 LIMIT 50",
				$row['event_date'], $row['partner_id'], $row['email']
			), ARRAY_A);
		}

		return $this->paginate($rows, $page, self::SUMMARY_PER_PAGE, $total);
	}

	private function query_raw_events(array $filters, int $page): array {
		global $wpdb;

		$params = [];
		$where = $this->build_where_sql($filters, $params);
		$per_page = $this->get_max_lines();
		$offset = max(0, ($page - 1) * $per_page);

		$total = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE {$where}",
			$params
		));

		$sql = "SELECT * FROM {$this->table_name}
			WHERE {$where}
			ORDER BY event_time DESC, id DESC
			LIMIT %d OFFSET %d";

		$params_with_limit = $params;
		$params_with_limit[] = $per_page;
		$params_with_limit[] = $offset;

		$rows = $wpdb->get_results($wpdb->prepare($sql, $params_with_limit), ARRAY_A);

		return $this->paginate($rows, $page, $per_page, $total, $rows);
	}

	private function query_bans(array $filters, int $page): array {
		global $wpdb;

		$params = [];
		$where = $this->build_where_sql($filters, $params);

		$sql = "SELECT t1.*
			FROM {$this->table_name} t1
			INNER JOIN (
				SELECT ip, MAX(id) AS max_id
				FROM {$this->table_name}
				WHERE ip <> '' AND {$where}
				GROUP BY ip
			) t2 ON t1.id = t2.max_id
			ORDER BY t1.event_time DESC";

		$rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

		foreach ($rows as &$row) {
			$is_banned = !empty($row['ip']) ? (bool) get_transient('partner_login_ban_' . md5($row['ip'])) : false;
			if ($is_banned) {
				$row['ban_status'] = 'active';
			} elseif ($row['event_type'] === 'UNLOCK') {
				$row['ban_status'] = 'unlocked';
			} elseif ($row['event_type'] === 'BAN' || $row['event_type'] === 'BLOCKED') {
				$row['ban_status'] = 'expired';
			} else {
				$row['ban_status'] = 'seen';
			}
		}

		return $this->paginate($rows, $page, self::BANS_PER_PAGE, count($rows));
	}

	private function paginate(array $items, int $page, int $per_page, ?int $total = null, ?array $paged_items = null): array {
		$total = $total ?? count($items);
		$total_pages = max(1, (int) ceil($total / max(1, $per_page)));
		$page = max(1, min($page, $total_pages));

		if ($paged_items === null) {
			$offset = ($page - 1) * $per_page;
			$paged_items = array_slice($items, $offset, $per_page);
		}

		return [
			'items' => $paged_items,
			'total' => $total,
			'total_pages' => $total_pages,
			'page' => $page,
			'per_page' => $per_page,
		];
	}


	public function handle_block_ip() {
		if (!$this->can_manage()) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('pllmg_block_ip');

		$ip = isset($_POST['ip']) ? sanitize_text_field(wp_unslash($_POST['ip'])) : '';
		if ($ip === '') {
			wp_safe_redirect(add_query_arg(['page' => 'partner-login-log-manager', 'msg' => 'ip_missing'], admin_url('admin.php')));
			exit;
		}

		$ban_key = 'partner_login_ban_' . md5($ip);
		set_transient($ban_key, 1, DAY_IN_SECONDS);

		error_log("PARTNER LOGIN MANUAL BAN | IP: {$ip} | durata: 24 ore | by admin: " . wp_get_current_user()->user_login);

		wp_safe_redirect(add_query_arg(['page' => 'partner-login-log-manager', 'msg' => 'blocked'], admin_url('admin.php')));
		exit;
	}

	public function handle_unlock_ip() {
		if (!$this->can_manage()) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('pllmg_unlock_ip');

		$ip = isset($_POST['ip']) ? sanitize_text_field(wp_unslash($_POST['ip'])) : '';
		if ($ip === '') {
			wp_safe_redirect(add_query_arg(['page' => 'partner-login-log-manager', 'msg' => 'ip_missing'], admin_url('admin.php')));
			exit;
		}

		delete_transient('partner_login_ban_' . md5($ip));
		delete_transient('partner_login_fail_short_' . md5($ip));
		delete_transient('partner_login_fail_long_' . md5($ip));

		error_log("PARTNER LOGIN MANUAL UNLOCK | IP: {$ip} | by admin: " . wp_get_current_user()->user_login);

		wp_safe_redirect(add_query_arg(['page' => 'partner-login-log-manager', 'msg' => 'unlocked'], admin_url('admin.php')));
		exit;
	}

	public function handle_save_settings() {
		if (!$this->can_manage()) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('pllmg_save_settings');

		$log_path = isset($_POST['pllmg_log_path']) ? sanitize_text_field(wp_unslash($_POST['pllmg_log_path'])) : '';
		$max_lines = isset($_POST['pllmg_max_lines']) ? (int) $_POST['pllmg_max_lines'] : self::RAW_PER_PAGE_DEFAULT;
		$sync_lines = isset($_POST['pllmg_sync_lines']) ? (int) $_POST['pllmg_sync_lines'] : 5000;

		update_option(self::OPTION_LOG_PATH, $log_path);
		update_option(self::OPTION_MAX_LINES, max(25, min(1000, $max_lines)));
		update_option(self::OPTION_SYNC_LINES, max(100, min(20000, $sync_lines)));

		wp_safe_redirect(add_query_arg(['page' => 'partner-login-log-manager', 'msg' => 'saved'], admin_url('admin.php')));
		exit;
	}

	public function handle_sync_db() {
		if (!$this->can_manage()) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('pllmg_sync_db');

		$stats = $this->sync_db_from_log();

		wp_safe_redirect(add_query_arg([
			'page' => 'partner-login-log-manager',
			'msg' => 'synced',
			'inserted' => (int) $stats['inserted'],
			'skipped_dirty' => (int) $stats['skipped_dirty'],
			'skipped_duplicate' => (int) $stats['skipped_duplicate'],
		], admin_url('admin.php')));
		exit;
	}

	public function handle_clear_db() {
		global $wpdb;

		if (!$this->can_manage()) {
			wp_die('Non autorizzato');
		}
		check_admin_referer('pllmg_clear_db');

		$wpdb->query("TRUNCATE TABLE {$this->table_name}");

		wp_safe_redirect(add_query_arg(['page' => 'partner-login-log-manager', 'msg' => 'db_cleared'], admin_url('admin.php')));
		exit;
	}

	private function render_notice(): void {
		if (!isset($_GET['msg'])) {
			return;
		}
		$msg = sanitize_text_field(wp_unslash($_GET['msg']));
		$text = '';

		if ($msg === 'saved') {
			$text = 'Impostazioni salvate.';
		} elseif ($msg === 'blocked') {
			$text = 'IP bloccato correttamente per 24 ore.';
		} elseif ($msg === 'unlocked') {
			$text = 'IP sbloccato correttamente.';
		} elseif ($msg === 'ip_missing') {
			$text = 'IP mancante.';
		} elseif ($msg === 'db_cleared') {
			$text = 'Tabella DB svuotata.';
		} elseif ($msg === 'synced') {
			$inserted = isset($_GET['inserted']) ? (int) $_GET['inserted'] : 0;
			$skipped_dirty = isset($_GET['skipped_dirty']) ? (int) $_GET['skipped_dirty'] : 0;
			$skipped_duplicate = isset($_GET['skipped_duplicate']) ? (int) $_GET['skipped_duplicate'] : 0;
			$text = "Sync completata. Importati: {$inserted} | Scartati sporchi: {$skipped_dirty} | Duplicati ignorati: {$skipped_duplicate}.";
		}

		if ($text !== '') {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($text) . '</p></div>';
		}
	}

	private function badge_style(string $type): string {
		switch ($type) {
			case 'OK':
				return 'background:#dcfce7;color:#166534;';
			case 'FAIL':
				return 'background:#fee2e2;color:#991b1b;';
			case 'BAN':
				return 'background:#fde68a;color:#92400e;';
			case 'BLOCKED':
				return 'background:#e5e7eb;color:#111827;';
			case 'UNLOCK':
				return 'background:#dbeafe;color:#1d4ed8;';
			case 'active':
				return 'background:#fee2e2;color:#991b1b;';
			case 'expired':
				return 'background:#fef3c7;color:#92400e;';
			case 'unlocked':
				return 'background:#dbeafe;color:#1d4ed8;';
			default:
				return 'background:#e5e7eb;color:#111827;';
		}
	}

	private function render_badge(string $label, string $type): string {
		return '<span style="display:inline-block;padding:4px 8px;border-radius:999px;font-weight:600;' . esc_attr($this->badge_style($type)) . '">' . esc_html($label) . '</span>';
	}

	private function render_pagination(string $param_name, array $pagination): void {
		if ($pagination['total_pages'] <= 1) {
			return;
		}

		echo '<div style="margin-top:12px;">';
		for ($i = 1; $i <= $pagination['total_pages']; $i++) {
			$url = add_query_arg($param_name, $i);
			if ($i === $pagination['page']) {
				echo '<span style="display:inline-block;padding:4px 8px;margin-right:4px;background:#2271b1;color:#fff;border-radius:4px;">' . (int) $i . '</span>';
			} else {
				echo '<a href="' . esc_url($url) . '" class="button button-small" style="margin-right:4px;">' . (int) $i . '</a>';
			}
		}
		echo '</div>';
	}

	public function render_page() {
		if (!$this->can_manage()) {
			wp_die('Non autorizzato');
		}

		// Sync automatica a ogni apertura pagina, come richiesto.
		$this->sync_db_from_log();

		$filters = $this->get_filters();
		$log_path = $this->get_log_path();
		$max_lines = $this->get_max_lines();
		$sync_lines = $this->get_sync_lines();

		$summary_page = isset($_GET['summary_page']) ? (int) $_GET['summary_page'] : 1;
		$raw_page = isset($_GET['raw_page']) ? (int) $_GET['raw_page'] : 1;
		$ban_page = isset($_GET['ban_page']) ? (int) $_GET['ban_page'] : 1;

		$summary = $this->query_summary($filters, $summary_page);
		$raw = $this->query_raw_events($filters, $raw_page);
		$bans = $this->query_bans($filters, $ban_page);

		echo '<div class="wrap">';
		echo '<h1>Partner Login Log</h1>';
		$this->render_notice();

		echo '<div style="display:grid;grid-template-columns:1.2fr 1fr;gap:20px;align-items:start;">';

		echo '<div style="background:#fff;border:1px solid #dcdcde;padding:16px;border-radius:8px;">';
		echo '<h2 style="margin-top:0;">Impostazioni log</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('pllmg_save_settings');
		echo '<input type="hidden" name="action" value="pllmg_save_settings">';

		echo '<p><label><strong>Percorso file log</strong></label><br><input type="text" name="pllmg_log_path" value="' . esc_attr($log_path) . '" style="width:100%;max-width:900px;"></p>';
		echo '<p><label><strong>Righe eventi grezzi per pagina</strong></label><br><input type="number" name="pllmg_max_lines" value="' . esc_attr((string) $max_lines) . '" min="25" max="1000"></p>';
		echo '<p><label><strong>Righe recenti da importare nel DB</strong></label><br><input type="number" name="pllmg_sync_lines" value="' . esc_attr((string) $sync_lines) . '" min="100" max="20000"></p>';
		echo '<p><button type="submit" class="button button-primary">Salva impostazioni</button></p>';
		echo '<p style="margin-top:12px;color:#50575e;">Questa versione legge e importa nel DB solo righe partner pulite, anche se il file resta error_log_php.</p>';

		echo '<p><strong>Percorsi suggeriti:</strong></p><ul style="margin-left:18px;">';
		foreach ($this->default_log_paths() as $path) {
			echo '<li><code>' . esc_html($path) . '</code></li>';
		}
		echo '</ul>';

		if (!empty($log_path)) {
			echo '<p><strong>Stato file:</strong> ';
			echo file_exists($log_path) ? '<span style="color:#166534;">trovato</span>' : '<span style="color:#991b1b;">non trovato</span>';
			echo ' — ';
			echo is_readable($log_path) ? '<span style="color:#166534;">leggibile</span>' : '<span style="color:#991b1b;">non leggibile</span>';
			echo '</p>';
		}
		echo '</form></div>';

		echo '<div style="display:grid;gap:20px;">';
		echo '<div style="background:#fff;border:1px solid #dcdcde;padding:16px;border-radius:8px;">';
		echo '<h2 style="margin-top:0;">Azioni rapide</h2>';

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:10px;">';
		wp_nonce_field('pllmg_sync_db');
		echo '<input type="hidden" name="action" value="pllmg_sync_db">';
		echo '<button type="submit" class="button button-primary">Sincronizza da file a DB</button>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Svuotare la tabella DB? Il file log non verrà toccato.\');" style="margin-bottom:10px;">';
		wp_nonce_field('pllmg_clear_db');
		echo '<input type="hidden" name="action" value="pllmg_clear_db">';
		echo '<button type="submit" class="button">Svuota tabella DB</button>';
		echo '</form>';

		echo '</div>';

		echo '<div style="background:#fff;border:1px solid #dcdcde;padding:16px;border-radius:8px;">';
		echo '<h2 style="margin-top:0;">Blocca IP manualmente</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:16px;">';
		wp_nonce_field('pllmg_block_ip');
		echo '<input type="hidden" name="action" value="pllmg_block_ip">';
		echo '<p><label><strong>IP</strong></label><br><input type="text" name="ip" placeholder="Es. 192.168.1.10" style="width:100%;max-width:320px;"></p>';
		echo '<p><button type="submit" class="button button-primary">Blocca IP (24h)</button></p>';
		echo '</form>';
		echo '<h2 style="margin-top:0;">Sblocca IP manualmente</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('pllmg_unlock_ip');
		echo '<input type="hidden" name="action" value="pllmg_unlock_ip">';
		echo '<p><label><strong>IP</strong></label><br><input type="text" name="ip" placeholder="Es. 192.168.1.10" style="width:100%;max-width:320px;"></p>';
		echo '<p><button type="submit" class="button">Sblocca IP</button></p>';
		echo '</form>';
		echo '</div>';
		echo '</div>';

		echo '</div>';

		echo '<div style="margin-top:20px;background:#fff;border:1px solid #dcdcde;padding:16px;border-radius:8px;">';
		echo '<h2 style="margin-top:0;">Filtri</h2>';
		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:12px;align-items:end;">';
		echo '<input type="hidden" name="page" value="partner-login-log-manager">';
		echo '<p><label><strong>Da data</strong><br><input type="date" name="date_from" value="' . esc_attr($filters['date_from']) . '" style="width:100%;"></label></p>';
		echo '<p><label><strong>A data</strong><br><input type="date" name="date_to" value="' . esc_attr($filters['date_to']) . '" style="width:100%;"></label></p>';
		echo '<p><label><strong>Tipo</strong><br><select name="event_type" style="width:100%;"><option value="">Tutti</option>';
		foreach (['OK','FAIL','BAN','BLOCKED','UNLOCK'] as $type) {
			echo '<option value="' . esc_attr($type) . '"' . selected($filters['event_type'], $type, false) . '>' . esc_html($type) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label><strong>Partner</strong><br><input type="text" name="partner" value="' . esc_attr($filters['partner']) . '" style="width:100%;"></label></p>';
		echo '<p><label><strong>Email</strong><br><input type="text" name="email" value="' . esc_attr($filters['email']) . '" style="width:100%;"></label></p>';
		echo '<p><label><strong>IP</strong><br><input type="text" name="ip" value="' . esc_attr($filters['ip']) . '" style="width:100%;"></label></p>';
		echo '<p><button type="submit" class="button button-primary">Applica filtri</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=partner-login-log-manager')) . '">Reset</a></p>';
		echo '</form></div>';

		echo '<div style="margin-top:20px;background:#fff;border:1px solid #dcdcde;padding:16px;border-radius:8px;">';
		echo '<h2 style="margin-top:0;">Sintesi giornaliera per utente</h2>';
		echo '<p style="margin-top:0;color:#50575e;">Una riga per giorno, partner e utente. Il dettaglio mostra gli eventi letti dal DB.</p>';

		if (empty($summary['items'])) {
			echo '<p>Nessun evento partner trovato nel DB per i filtri selezionati.</p>';
		} else {
			echo '<div style="overflow:auto;">';
			echo '<table class="widefat striped" style="min-width:1280px;">';
			echo '<thead><tr><th>Data</th><th>Partner</th><th>Email</th><th>Primo OK</th><th>Ultimo OK</th><th>OK</th><th>Fail</th><th>Ban/Blocked</th><th>Ultimo IP</th><th>Dettaglio</th></tr></thead><tbody>';

			foreach ($summary['items'] as $row) {
				echo '<tr>';
				echo '<td>' . esc_html($row['event_date']) . '</td>';
				echo '<td>' . esc_html($row['partner_id']) . '</td>';
				echo '<td>' . esc_html($row['email']) . '</td>';
				echo '<td>' . esc_html((string) $row['first_ok_at']) . '</td>';
				echo '<td>' . esc_html((string) $row['last_ok_at']) . '</td>';
				echo '<td>' . esc_html((string) $row['ok_count']) . '</td>';
				echo '<td>' . esc_html((string) $row['fail_count']) . '</td>';
				echo '<td>' . esc_html((string) $row['ban_blocked_count']) . '</td>';
				echo '<td>' . esc_html((string) $row['last_ip']) . '</td>';
				echo '<td><details><summary>Mostra</summary><div style="margin-top:10px;max-width:900px;">';
				if (!empty($row['last_user_agent'])) {
					echo '<p><strong>Ultimo User Agent:</strong><br><span style="display:block;max-width:760px;white-space:normal;word-break:break-word;">' . esc_html($row['last_user_agent']) . '</span></p>';
				}
				echo '<table class="widefat striped" style="margin-top:8px;min-width:640px;">';
				echo '<thead><tr><th>Data</th><th>Tipo</th><th>IP</th><th>Motivo</th></tr></thead><tbody>';
				foreach ($row['details'] as $detail) {
					echo '<tr>';
					echo '<td>' . esc_html($detail['event_time']) . '</td>';
					echo '<td>' . $this->render_badge($detail['event_type'], $detail['event_type']) . '</td>';
					echo '<td>' . esc_html($detail['ip']) . '</td>';
					echo '<td style="max-width:260px;white-space:normal;word-break:break-word;">' . esc_html((string) $detail['reason']) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table></div></details></td>';
				echo '</tr>';
			}

			echo '</tbody></table></div>';
			$this->render_pagination('summary_page', $summary);
		}
		echo '</div>';

		echo '<div style="margin-top:20px;background:#fff;border:1px solid #dcdcde;padding:16px;border-radius:8px;">';
		echo '<h2 style="margin-top:0;">IP con stato ban</h2>';
		echo '<p style="margin-top:0;color:#50575e;">Il pulsante "Sblocca" compare solo per IP con ban attivo in questo momento.</p>';

		if (empty($bans['items'])) {
			echo '<p>Nessun IP trovato nel DB.</p>';
		} else {
			echo '<div style="overflow:auto;">';
			echo '<table class="widefat striped" style="min-width:1100px;">';
			echo '<thead><tr><th>IP</th><th>Stato</th><th>Ultimo evento</th><th>Partner</th><th>Email</th><th>Motivo</th><th>Durata</th><th>Azione</th></tr></thead><tbody>';
			foreach ($bans['items'] as $row) {
				echo '<tr>';
				echo '<td>' . esc_html($row['ip']) . '</td>';
				echo '<td>' . $this->render_badge(strtoupper($row['ban_status']), $row['ban_status']) . '</td>';
				echo '<td>' . esc_html($row['event_time']) . '</td>';
				echo '<td>' . esc_html($row['partner_id']) . '</td>';
				echo '<td>' . esc_html($row['email']) . '</td>';
				echo '<td style="max-width:260px;white-space:normal;word-break:break-word;">' . esc_html($row['reason']) . '</td>';
				echo '<td>' . esc_html($row['duration']) . '</td>';
				echo '<td>';
				if ($row['ban_status'] === 'active') {
					echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
					wp_nonce_field('pllmg_unlock_ip');
					echo '<input type="hidden" name="action" value="pllmg_unlock_ip">';
					echo '<input type="hidden" name="ip" value="' . esc_attr($row['ip']) . '">';
					echo '<button type="submit" class="button button-small">Sblocca</button>';
					echo '</form>';
				} elseif (!empty($row['ip'])) {
					echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
					wp_nonce_field('pllmg_block_ip');
					echo '<input type="hidden" name="action" value="pllmg_block_ip">';
					echo '<input type="hidden" name="ip" value="' . esc_attr($row['ip']) . '">';
					echo '<button type="submit" class="button button-small">Blocca</button>';
					echo '</form>';
				} else {
					echo '—';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			$this->render_pagination('ban_page', $bans);
		}
		echo '</div>';

		echo '<div style="margin-top:20px;background:#fff;border:1px solid #dcdcde;padding:16px;border-radius:8px;">';
		echo '<h2 style="margin-top:0;">Eventi partner-login (DB)</h2>';

		if (empty($raw['items'])) {
			echo '<p>Nessun evento partner trovato nel DB per i filtri selezionati.</p>';
		} else {
			echo '<div style="overflow:auto;">';
			echo '<table class="widefat striped" style="min-width:1520px;">';
			echo '<thead><tr><th>Data</th><th>Tipo</th><th>Partner</th><th>Email</th><th>IP</th><th style="width:260px;">Motivo</th><th>Durata</th><th style="width:520px;">User Agent</th><th>Azione</th></tr></thead><tbody>';

			foreach ($raw['items'] as $row) {
				echo '<tr>';
				echo '<td>' . esc_html($row['event_time']) . '</td>';
				echo '<td>' . $this->render_badge($row['event_type'], $row['event_type']) . '</td>';
				echo '<td>' . esc_html($row['partner_id']) . '</td>';
				echo '<td>' . esc_html($row['email']) . '</td>';
				echo '<td>' . esc_html($row['ip']) . '</td>';
				echo '<td style="max-width:260px;white-space:normal;word-break:break-word;">' . esc_html($row['reason']) . '</td>';
				echo '<td>' . esc_html($row['duration']) . '</td>';
				echo '<td style="max-width:520px;white-space:normal;word-break:break-word;">' . esc_html($row['user_agent']) . '</td>';
				echo '<td>';
				if (!empty($row['ip']) && get_transient('partner_login_ban_' . md5($row['ip']))) {
					echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
					wp_nonce_field('pllmg_unlock_ip');
					echo '<input type="hidden" name="action" value="pllmg_unlock_ip">';
					echo '<input type="hidden" name="ip" value="' . esc_attr($row['ip']) . '">';
					echo '<button type="submit" class="button button-small">Sblocca</button>';
					echo '</form>';
				} elseif (!empty($row['ip'])) {
					echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
					wp_nonce_field('pllmg_block_ip');
					echo '<input type="hidden" name="action" value="pllmg_block_ip">';
					echo '<input type="hidden" name="ip" value="' . esc_attr($row['ip']) . '">';
					echo '<button type="submit" class="button button-small">Blocca</button>';
					echo '</form>';
				} else {
					echo '—';
				}
				echo '</td></tr>';
			}

			echo '</tbody></table></div>';
			$this->render_pagination('raw_page', $raw);
		}
		echo '</div>';

		echo '</div>';
	}
}

new Partner_Login_Log_Manager();
?>