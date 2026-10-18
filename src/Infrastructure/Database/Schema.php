<?php
/**
 * Datenbankschema des Plugins.
 *
 * @package Lsec
 */

namespace Lsec\Infrastructure\Database;

/**
 * Definiert alle Plugin-Tabellen für dbDelta().
 *
 * Regeln für dbDelta: jede Spalte in eigener Zeile, "PRIMARY KEY" mit zwei Leerzeichen,
 * Indizes als "KEY", keine Backticks. Jede Änderung erhöht VERSION.
 */
final class Schema {

	/**
	 * Aktuelle Schemaversion. Bei jeder Änderung erhöhen.
	 */
	public const VERSION = 2;

	/**
	 * Option, in der die installierte Schemaversion steht.
	 */
	public const VERSION_OPTION = 'lsec_db_version';

	/**
	 * Tabellennamen ohne WordPress- und Plugin-Präfix.
	 *
	 * @var list<string>
	 */
	public const TABLES = array(
		'entities',
		'entity_aliases',
		'relations',
		'sources',
		'claims',
		'evidence',
		'mentions',
		'keywords',
		'keyword_metrics',
		'imports',
		'topics',
		'topic_entities',
		'topic_keywords',
		'briefs',
		'runs',
		'run_steps',
		'ai_calls',
		'provider_calls',
		'quality_reports',
		'style_profiles',
	);

	/**
	 * Vollständiger Tabellenname mit Präfix.
	 *
	 * @param string $wp_prefix WordPress-Tabellenpräfix.
	 * @param string $table     Tabellenname aus TABLES.
	 */
	public static function table( string $wp_prefix, string $table ): string {
		return $wp_prefix . 'lsec_' . $table;
	}

	/**
	 * CREATE-TABLE-Anweisungen für dbDelta().
	 *
	 * @param string $wp_prefix WordPress-Tabellenpräfix.
	 * @param string $collate   Zeichensatz und Sortierung, z. B. aus $wpdb->get_charset_collate().
	 * @return array<string, string> Tabellenname => SQL.
	 */
	public static function statements( string $wp_prefix, string $collate ): array {
		$t   = static fn( string $name ): string => self::table( $wp_prefix, $name );
		$sql = array();

		$sql['entities'] = "CREATE TABLE {$t('entities')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
uuid char(36) NOT NULL,
type varchar(50) NOT NULL,
canonical_name varchar(255) NOT NULL,
normalized_name varchar(255) NOT NULL,
description text NULL,
properties longtext NULL,
external_ids longtext NULL,
status varchar(20) NOT NULL DEFAULT 'candidate',
confidence decimal(4,3) NULL,
origin varchar(30) NOT NULL,
decided_by bigint(20) unsigned NULL,
decided_at datetime NULL,
last_verified_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY uuid (uuid),
KEY normalized_name (normalized_name(191)),
KEY status (status),
KEY type (type)
) $collate;";

		$sql['entity_aliases'] = "CREATE TABLE {$t('entity_aliases')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
entity_id bigint(20) unsigned NOT NULL,
alias varchar(255) NOT NULL,
normalized_alias varchar(255) NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY entity_id (entity_id),
KEY normalized_alias (normalized_alias(191))
) $collate;";

		$sql['relations'] = "CREATE TABLE {$t('relations')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
source_entity_id bigint(20) unsigned NOT NULL,
relation_type varchar(50) NOT NULL,
target_entity_id bigint(20) unsigned NOT NULL,
status varchar(20) NOT NULL DEFAULT 'candidate',
confidence decimal(4,3) NULL,
origin varchar(30) NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY edge (source_entity_id,relation_type,target_entity_id),
KEY target_entity_id (target_entity_id)
) $collate;";

		$sql['sources'] = "CREATE TABLE {$t('sources')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
url text NOT NULL,
url_hash char(64) NOT NULL,
title varchar(255) NULL,
source_type varchar(30) NOT NULL,
fetched_at datetime NULL,
content_hash char(64) NULL,
reliability varchar(10) NOT NULL DEFAULT 'unknown',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY url_hash (url_hash)
) $collate;";

		$sql['claims'] = "CREATE TABLE {$t('claims')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
subject_type varchar(20) NOT NULL,
subject_id bigint(20) unsigned NOT NULL,
property varchar(100) NOT NULL,
value longtext NOT NULL,
valid_from datetime NULL,
valid_to datetime NULL,
status varchar(20) NOT NULL DEFAULT 'candidate',
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY subject (subject_type,subject_id),
KEY property (property)
) $collate;";

		$sql['evidence'] = "CREATE TABLE {$t('evidence')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
source_id bigint(20) unsigned NOT NULL,
target_type varchar(20) NOT NULL,
target_id bigint(20) unsigned NOT NULL,
excerpt text NULL,
locator varchar(255) NULL,
quality varchar(10) NOT NULL DEFAULT 'medium',
captured_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY source_id (source_id),
KEY target (target_type,target_id)
) $collate;";

		$sql['mentions'] = "CREATE TABLE {$t('mentions')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
entity_id bigint(20) unsigned NOT NULL,
object_type varchar(20) NOT NULL,
object_id bigint(20) unsigned NULL,
source_id bigint(20) unsigned NULL,
location varchar(20) NOT NULL,
excerpt text NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY entity_id (entity_id),
KEY object (object_type,object_id)
) $collate;";

		$sql['keywords'] = "CREATE TABLE {$t('keywords')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
keyword varchar(255) NOT NULL,
normalized varchar(191) NOT NULL,
locale varchar(10) NOT NULL,
region varchar(100) NOT NULL DEFAULT '',
intent varchar(20) NULL,
first_seen_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY keyword_scope (normalized,locale,region)
) $collate;";

		$sql['keyword_metrics'] = "CREATE TABLE {$t('keyword_metrics')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
keyword_id bigint(20) unsigned NOT NULL,
source varchar(40) NOT NULL,
period_start date NULL,
period_end date NULL,
search_volume int(10) unsigned NULL,
difficulty decimal(5,2) NULL,
cpc decimal(8,2) NULL,
impressions int(10) unsigned NULL,
clicks int(10) unsigned NULL,
ctr decimal(6,4) NULL,
position decimal(6,2) NULL,
page_url text NULL,
trend longtext NULL,
import_batch_id bigint(20) unsigned NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY keyword_source (keyword_id,source),
KEY import_batch_id (import_batch_id)
) $collate;";

		$sql['imports'] = "CREATE TABLE {$t('imports')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
source varchar(40) NOT NULL,
filename varchar(255) NOT NULL,
rows_total int(10) unsigned NOT NULL DEFAULT 0,
rows_imported int(10) unsigned NOT NULL DEFAULT 0,
rows_skipped int(10) unsigned NOT NULL DEFAULT 0,
column_mapping longtext NULL,
notes longtext NULL,
created_by bigint(20) unsigned NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id)
) $collate;";

		$sql['topics'] = "CREATE TABLE {$t('topics')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
title varchar(255) NOT NULL,
intent varchar(20) NULL,
format varchar(30) NOT NULL,
region varchar(100) NULL,
priority_score decimal(5,2) NULL,
score_breakdown longtext NULL,
rationale text NULL,
research_basis longtext NULL,
overlap longtext NULL,
status varchar(20) NOT NULL DEFAULT 'proposed',
post_id bigint(20) unsigned NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY status (status)
) $collate;";

		$sql['topic_entities'] = "CREATE TABLE {$t('topic_entities')} (
topic_id bigint(20) unsigned NOT NULL,
entity_id bigint(20) unsigned NOT NULL,
role varchar(10) NOT NULL,
PRIMARY KEY  (topic_id,entity_id),
KEY entity_id (entity_id)
) $collate;";

		$sql['topic_keywords'] = "CREATE TABLE {$t('topic_keywords')} (
topic_id bigint(20) unsigned NOT NULL,
keyword_id bigint(20) unsigned NOT NULL,
role varchar(10) NOT NULL,
PRIMARY KEY  (topic_id,keyword_id),
KEY keyword_id (keyword_id)
) $collate;";

		$sql['briefs'] = "CREATE TABLE {$t('briefs')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
topic_id bigint(20) unsigned NOT NULL,
version int(10) unsigned NOT NULL DEFAULT 1,
payload longtext NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY topic_id (topic_id)
) $collate;";

		$sql['runs'] = "CREATE TABLE {$t('runs')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
uuid char(36) NOT NULL,
type varchar(40) NOT NULL,
subject_type varchar(20) NULL,
subject_id bigint(20) unsigned NULL,
status varchar(20) NOT NULL DEFAULT 'queued',
current_step varchar(40) NULL,
idempotency_key char(64) NOT NULL,
context longtext NULL,
locked_until datetime NULL,
lock_token char(32) NULL,
error_code varchar(60) NULL,
error_message text NULL,
created_by bigint(20) unsigned NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
finished_at datetime NULL,
PRIMARY KEY  (id),
UNIQUE KEY uuid (uuid),
UNIQUE KEY idempotency_key (idempotency_key),
KEY status (status),
KEY subject (subject_type,subject_id)
) $collate;";

		$sql['run_steps'] = "CREATE TABLE {$t('run_steps')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
run_id bigint(20) unsigned NOT NULL,
step varchar(40) NOT NULL,
position smallint(5) unsigned NOT NULL,
status varchar(20) NOT NULL DEFAULT 'pending',
attempt smallint(5) unsigned NOT NULL DEFAULT 0,
max_attempts smallint(5) unsigned NOT NULL DEFAULT 3,
input_hash char(64) NULL,
output longtext NULL,
error_code varchar(60) NULL,
error_message text NULL,
started_at datetime NULL,
finished_at datetime NULL,
PRIMARY KEY  (id),
UNIQUE KEY run_step (run_id,step),
KEY run_position (run_id,position)
) $collate;";

		$sql['ai_calls'] = "CREATE TABLE {$t('ai_calls')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
run_id bigint(20) unsigned NULL,
step_id bigint(20) unsigned NULL,
task varchar(40) NOT NULL,
provider varchar(30) NOT NULL,
model varchar(100) NOT NULL,
input_tokens int(10) unsigned NULL,
output_tokens int(10) unsigned NULL,
cost_estimate decimal(10,6) NULL,
duration_ms int(10) unsigned NULL,
status varchar(20) NOT NULL,
request_hash char(64) NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY run_id (run_id),
KEY created_at (created_at)
) $collate;";

		$sql['provider_calls'] = "CREATE TABLE {$t('provider_calls')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
provider varchar(30) NOT NULL,
endpoint varchar(60) NOT NULL,
request_hash char(64) NOT NULL,
credits_used decimal(10,2) NULL,
credits_remaining decimal(12,2) NULL,
status varchar(20) NOT NULL,
response_ref varchar(100) NULL,
response_body longtext NULL,
cached_until datetime NULL,
run_id bigint(20) unsigned NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY request_hash (request_hash),
KEY provider_day (provider,created_at)
) $collate;";

		$sql['quality_reports'] = "CREATE TABLE {$t('quality_reports')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
post_id bigint(20) unsigned NULL,
run_id bigint(20) unsigned NULL,
profile varchar(40) NOT NULL,
score decimal(5,2) NULL,
passed tinyint(1) NOT NULL DEFAULT 0,
blocking_issues longtext NULL,
warnings longtext NULL,
checks longtext NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY post_id (post_id),
KEY run_id (run_id)
) $collate;";

		$sql['style_profiles'] = "CREATE TABLE {$t('style_profiles')} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
version int(10) unsigned NOT NULL,
profile longtext NOT NULL,
inputs longtext NULL,
is_active tinyint(1) NOT NULL DEFAULT 0,
created_by bigint(20) unsigned NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY is_active (is_active)
) $collate;";

		return $sql;
	}
}
