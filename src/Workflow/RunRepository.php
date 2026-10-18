<?php
/**
 * Datenzugriff für Abläufe und Schritte.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

use Lsec\Infrastructure\Database\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Eigene Tabellen, Zustand muss immer frisch gelesen werden.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellennamen stammen aus Schema::table(), nicht aus Eingaben.

/**
 * Liest und schreibt lsec_runs und lsec_run_steps.
 *
 * Alle Zeitstempel sind UTC. Sperren werden in der Datenbank mit UTC_TIMESTAMP()
 * verglichen, damit Uhrabweichungen zwischen PHP und Datenbank keine Rolle spielen.
 */
final class RunRepository {

	/**
	 * WordPress-Datenbank.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Konstruktor.
	 *
	 * @param \wpdb|null $db Datenbank, Standard: globales $wpdb.
	 */
	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	/**
	 * Tabellenname.
	 *
	 * @param string $table Kurzname.
	 */
	private function t( string $table ): string {
		return Schema::table( $this->db->prefix, $table );
	}

	/**
	 * Aktuelle Zeit in UTC.
	 */
	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Legt einen Ablauf mit seinen Schritten an. Gibt es den Idempotenz-Schlüssel schon,
	 * wird der vorhandene Ablauf zurückgegeben.
	 *
	 * @param string               $type            Ablauftyp.
	 * @param string|null          $subject_type    Gegenstand.
	 * @param int|null             $subject_id      ID des Gegenstands.
	 * @param string               $idempotency_key Schlüssel.
	 * @param array<string, mixed> $input           Startkontext.
	 * @param string[]             $steps           Schritte in Reihenfolge.
	 * @param int                  $max_attempts    Höchstzahl Versuche je Schritt.
	 * @param int|null             $created_by      WordPress-Benutzer.
	 * @return array{0: int, 1: bool} Ablauf-ID und ob er neu angelegt wurde.
	 * @throws \RuntimeException Wenn der Ablauf nicht gespeichert werden konnte.
	 */
	public function create( string $type, ?string $subject_type, ?int $subject_id, string $idempotency_key, array $input, array $steps, int $max_attempts, ?int $created_by ): array {
		$now      = self::now();
		$inserted = $this->db->query(
			$this->db->prepare(
				"INSERT IGNORE INTO {$this->t( 'runs' )}
				(uuid, type, subject_type, subject_id, status, idempotency_key, context, created_by, created_at, updated_at)
				VALUES (%s, %s, %s, %d, %s, %s, %s, %d, %s, %s)",
				wp_generate_uuid4(),
				$type,
				(string) $subject_type,
				(int) $subject_id,
				RunStatus::Queued->value,
				$idempotency_key,
				(string) wp_json_encode( $input ),
				(int) $created_by,
				$now,
				$now
			)
		);

		$run_id = (int) $this->db->get_var(
			$this->db->prepare( "SELECT id FROM {$this->t( 'runs' )} WHERE idempotency_key = %s", $idempotency_key )
		);
		if ( 0 === $run_id ) {
			throw new \RuntimeException( 'Ablauf konnte nicht angelegt werden: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$created = 1 === $inserted;
		if ( $created ) {
			foreach ( array_values( $steps ) as $position => $step ) {
				$this->db->query(
					$this->db->prepare(
						"INSERT IGNORE INTO {$this->t( 'run_steps' )} (run_id, step, position, status, attempt, max_attempts)
						VALUES (%d, %s, %d, %s, 0, %d)",
						$run_id,
						$step,
						$position,
						StepStatus::Pending->value,
						$max_attempts
					)
				);
			}
		}

		return array( $run_id, $created );
	}

	/**
	 * Ein Ablauf als Array oder null.
	 *
	 * @param int $run_id Ablauf-ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $run_id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->t( 'runs' )} WHERE id = %d", $run_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Schritte eines Ablaufs in Reihenfolge.
	 *
	 * @param int $run_id Ablauf-ID.
	 * @return list<array<string, mixed>>
	 */
	public function steps( int $run_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( "SELECT * FROM {$this->t( 'run_steps' )} WHERE run_id = %d ORDER BY position ASC", $run_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Versucht, die Sperre zu bekommen. Gelingt nur, wenn keine gültige Sperre besteht.
	 *
	 * @param int $run_id  Ablauf-ID.
	 * @param int $seconds Gültigkeit der Sperre.
	 * @return string|null Sperr-Token oder null.
	 */
	public function acquire_lock( int $run_id, int $seconds ): ?string {
		$token    = bin2hex( random_bytes( 16 ) );
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->t( 'runs' )}
				SET lock_token = %s, locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), updated_at = %s
				WHERE id = %d AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP())",
				$token,
				$seconds,
				self::now(),
				$run_id
			)
		);
		return 1 === $affected ? $token : null;
	}

	/**
	 * Gibt die Sperre frei, aber nur mit dem passenden Token.
	 *
	 * @param int    $run_id Ablauf-ID.
	 * @param string $token  Sperr-Token.
	 */
	public function release_lock( int $run_id, string $token ): void {
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->t( 'runs' )} SET lock_token = NULL, locked_until = NULL WHERE id = %d AND lock_token = %s",
				$run_id,
				$token
			)
		);
	}

	/**
	 * Aktualisiert Felder eines Ablaufs.
	 *
	 * @param int                  $run_id Ablauf-ID.
	 * @param array<string, mixed> $fields Spalten => Werte.
	 */
	public function update_run( int $run_id, array $fields ): void {
		$fields['updated_at'] = self::now();
		$this->db->update( $this->t( 'runs' ), $fields, array( 'id' => $run_id ) );
	}

	/**
	 * Markiert einen Schritt als laufend und zählt den Versuch hoch.
	 *
	 * @param int $step_id Schritt-ID.
	 * @return int Nummer des jetzt beginnenden Versuchs.
	 */
	public function start_step( int $step_id ): int {
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->t( 'run_steps' )} SET status = %s, attempt = attempt + 1, started_at = %s, finished_at = NULL WHERE id = %d",
				StepStatus::Running->value,
				self::now(),
				$step_id
			)
		);
		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT attempt FROM {$this->t( 'run_steps' )} WHERE id = %d", $step_id )
		);
	}

	/**
	 * Schließt einen Schrittversuch ab.
	 *
	 * @param int                       $step_id Schritt-ID.
	 * @param StepStatus                $status  Neuer Status.
	 * @param array<string, mixed>|null $output  Zwischenergebnis.
	 * @param string|null               $code    Fehlercode.
	 * @param string|null               $message Meldung.
	 */
	public function finish_step( int $step_id, StepStatus $status, ?array $output = null, ?string $code = null, ?string $message = null ): void {
		$fields = array(
			'status'        => $status->value,
			'error_code'    => $code,
			'error_message' => $message,
			'finished_at'   => self::now(),
		);
		if ( null !== $output ) {
			$fields['output'] = (string) wp_json_encode( $output );
		}
		$this->db->update( $this->t( 'run_steps' ), $fields, array( 'id' => $step_id ) );
	}

	/**
	 * Abläufe, die eigentlich weiterlaufen müssten, aber keine gültige Sperre haben und
	 * seit einer Weile nicht verändert wurden (z. B. nach einem Prozessabbruch).
	 *
	 * @param int $idle_seconds Mindestdauer ohne Änderung.
	 * @param int $limit        Höchstzahl.
	 * @return list<int> Ablauf-IDs.
	 */
	public function stale_run_ids( int $idle_seconds = 60, int $limit = 50 ): array {
		$ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM {$this->t( 'runs' )}
				WHERE status IN (%s, %s)
				AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP())
				AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)
				ORDER BY updated_at ASC
				LIMIT %d",
				RunStatus::Queued->value,
				RunStatus::Running->value,
				$idle_seconds,
				$limit
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Anzahl Abläufe je Status.
	 *
	 * @return array<string, int>
	 */
	public function count_by_status(): array {
		$rows   = $this->db->get_results( "SELECT status, COUNT(*) AS n FROM {$this->t( 'runs' )} GROUP BY status", ARRAY_A );
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Letzte Abläufe für die Statusseite.
	 *
	 * @param int $limit Anzahl.
	 * @return list<array<string, mixed>>
	 */
	public function recent( int $limit = 20 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, uuid, type, status, current_step, error_code, created_at, updated_at, finished_at
				FROM {$this->t( 'runs' )} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? array_values( $rows ) : array();
	}
}
