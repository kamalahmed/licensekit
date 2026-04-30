<?php
/**
 * Abstract repository — generic CRUD against a single table via $wpdb.
 *
 * Concrete subclasses declare their unprefixed table name and model class,
 * and add typed `find_by_*` helpers as needed. All values flow through
 * `$wpdb->prepare()`.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Repositories;

defined( 'ABSPATH' ) || exit;

abstract class Repository {

	abstract protected function table_name_unprefixed(): string;

	/**
	 * Fully-qualified class name of the model this repository hydrates.
	 * Model must expose a public static `from_row(array): self`.
	 */
	abstract protected function model_class(): string;

	protected function table(): string {
		global $wpdb;
		return $wpdb->prefix . $this->table_name_unprefixed();
	}

	/**
	 * @return \wpdb
	 */
	protected function wpdb() {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * @param int $id
	 * @return object|null Hydrated model instance, or null if not found.
	 */
	public function find( int $id ) {
		$row = $this->wpdb()->get_row(
			$this->wpdb()->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		return $row ? $this->hydrate( $row ) : null;
	}

	public function exists( int $id ): bool {
		$found = $this->wpdb()->get_var(
			$this->wpdb()->prepare( "SELECT id FROM {$this->table()} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB
		);
		return null !== $found;
	}

	public function count_all(): int {
		return (int) $this->wpdb()->get_var( "SELECT COUNT(*) FROM {$this->table()}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Insert a new row from a model. The model must provide `to_array()`.
	 *
	 * @param object $model Model instance with a public `to_array(): array` method.
	 * @return int New row ID, or 0 on failure.
	 */
	public function insert( $model ): int {
		$data = $model->to_array();
		unset( $data['id'] );
		// Drop nulls on INSERT so MySQL column defaults take effect.
		$data = array_filter( $data, static fn( $v ) => null !== $v );
		$ok   = $this->wpdb()->insert( $this->table(), $data );
		return $ok ? (int) $this->wpdb()->insert_id : 0;
	}

	/**
	 * Update specific columns by id. Nulls are preserved (caller may want to clear a column).
	 *
	 * @param int                  $id
	 * @param array<string, mixed> $changes Column-keyed changes.
	 * @return bool True if a row was updated.
	 */
	public function update( int $id, array $changes ): bool {
		if ( empty( $changes ) ) {
			return false;
		}
		$rows = $this->wpdb()->update( $this->table(), $changes, [ 'id' => $id ] );
		return false !== $rows && $rows > 0;
	}

	public function delete( int $id ): bool {
		$rows = $this->wpdb()->delete( $this->table(), [ 'id' => $id ], [ '%d' ] );
		return false !== $rows && $rows > 0;
	}

	/**
	 * Find a single row matching a parameterized WHERE clause.
	 *
	 * @param string  $where_sql Placeholder-ed clause, e.g. "slug = %s".
	 * @param array   $values    Prepare values.
	 * @param ?string $order_by  Optional whitelisted ORDER BY (NOT user input).
	 * @return object|null
	 */
	protected function find_one_where( string $where_sql, array $values, ?string $order_by = null ) {
		$sql = "SELECT * FROM {$this->table()} WHERE {$where_sql}";
		if ( null !== $order_by ) {
			$sql .= " ORDER BY {$order_by}";
		}
		$sql .= ' LIMIT 1';
		$row = $this->wpdb()->get_row(
			$this->wpdb()->prepare( $sql, $values ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Find many rows matching a parameterized WHERE clause.
	 *
	 * @param string   $where_sql Placeholder-ed clause.
	 * @param array    $values    Prepare values for the WHERE.
	 * @param string   $order_by  Whitelisted ORDER BY clause (NOT user input).
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return array<int, object>
	 */
	protected function find_many_where(
		string $where_sql,
		array $values,
		string $order_by = 'id ASC',
		?int $limit = null,
		?int $offset = null
	): array {
		$sql = "SELECT * FROM {$this->table()} WHERE {$where_sql} ORDER BY {$order_by}";
		if ( null !== $limit ) {
			$sql .= ' LIMIT ' . (int) $limit;
			if ( null !== $offset ) {
				$sql .= ' OFFSET ' . (int) $offset;
			}
		}
		$rows = $this->wpdb()->get_results(
			$this->wpdb()->prepare( $sql, $values ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	protected function count_where( string $where_sql, array $values ): int {
		$sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where_sql}";
		return (int) $this->wpdb()->get_var(
			$this->wpdb()->prepare( $sql, $values ) // phpcs:ignore WordPress.DB
		);
	}

	protected function delete_where( string $where_sql, array $values ): int {
		$sql  = "DELETE FROM {$this->table()} WHERE {$where_sql}";
		$rows = $this->wpdb()->query(
			$this->wpdb()->prepare( $sql, $values ) // phpcs:ignore WordPress.DB
		);
		return false === $rows ? 0 : (int) $rows;
	}

	/**
	 * Convert a model row to a hydrated instance.
	 *
	 * @param array $row
	 * @return object
	 */
	protected function hydrate( array $row ) {
		$class = $this->model_class();
		return $class::from_row( $row );
	}

}
