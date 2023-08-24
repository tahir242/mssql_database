<?php

class Database {

	/**
	 * The last ran query
	 *
	 * The last query is retained in case you want to do extended error handling in some way
	 *
	 * @access private
	 * @var string
	*/
	private $last_query = '';

	/**
	 * The last Id from an sql->insert call
	 *
	 * @access private
	 * @var int
	 */
	private $last_insert_id = null;

	/**
	 * Hold all errors encountered while processing a query/class construct
	 *
	 * @access private
	 * @var array
	 */
	public $error = array();

	/**
	 * The database connection is held here
	 *
	 * @access public
	 * @var false|null|resource
	 */
	public $conn = null;

	/**
	 * The Database Schema is read into memory and kept here
	 *
	 * This is done because MSSQL is very picky about data types and containers, so if enabled
	 * the class will download the schema and keep it on hand to properly handle various data types
	 *
	 * @access private
	 * @var array|bool|mixed
	 */
	private $schema = true;

	/**
	 * The storage location for the DB schema
	 *
	 * @access private
	 * @var null|string
	 */
	private $schema_location = null;

	/**
	 * The numbrs of rows affected by a query
	 *
	 * @var int
	 */
	public $num_rows = 0;

	/**
	 * If a query has returned any rows or not
	 *
	 * @var bool
	 */
	public $has_rows = false;
	
	/**
	 * If a query has returned any rows or not
	 *
	 * @var bool
	 * @var INT
	 */
	public $rows_effected = false;

	/**
	 * If a connection ot the database exists
	 *
	 * @var bool
	 */
	public $is_connected = false;

	/**
	 * Database Username
	 *
	 * @access protected
	 * @var string
	 */
	protected $dbuser;

	/**
	 * Database Password
	 *
	 * @access protected
	 * @var string
	 */
	protected $dbpassword;

	/**
	 * Database Host
	 * @var string
	 */
	protected $dbhost;

	/**
	 * Database Name
	 * @var string
	 */
	protected $dbname;

	/**
	 * SQLSRV_DataBase constructor.
	 *
	 *
	 * @param string $dbuser       MSSQL database user
	 * @param string $dbpassword   MSSQL database password
	 * @param string $dbname       MSSQL database name
	 * @param string $dbhost       MSSQL database host
	 * @param mixed  $build_schema Where (if at all) to store the DB schema
	 */
	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost, $build_schema = true ) {
		$this->dbuser       = $dbuser;
		$this->dbpassword   = $dbpassword;
		$this->dbname       = $dbname;
		$this->dbhost       = $dbhost;

		$this->is_connected = $this->db_connect();

		// If we've chosen to build a database schema, this is done on construct
		if ( $this->is_connected && $build_schema ) {
			if ( is_string( $build_schema ) ) {
				$this->schema_location = $build_schema;
			}
			else {
				// Set the schema store location to be alongside the DB class
				$this->schema_location = dirname( __FILE__ );
			}
			$this->schema = $this->build_schema();
		}
	}

	/**
	 * Connect to and select database
	 *
	 * @return bool
	 */
	public function db_connect() {
		$serverName = $this->dbhost;
		$connectionOptions = array(
			"Database" => $this->dbname,
			"UID"      => $this->dbuser,
			"PWD"      => $this->dbpassword,
			"Encrypt"	=> true,
			"TrustServerCertificate"=> true
		);

		// Create the connection resource
		$this->conn = sqlsrv_connect( $serverName, $connectionOptions );

		// If the connection fails we get a false value and build our error log
		if ( false === $this->conn )
		{
			/*
			 * We don't use log_error() here as the values passed from a failed connection
			 * are not compatible with the errors passed from a failed query
			*/
			$error = sqlsrv_errors();
			$this->error[] = $error;
			error_log("\n\n" . 'Database failure: ' . print_r($error, true), 3, "mssql_log.txt" );
			return false;

		}
		sqlsrv_configure( 'WarningsReturnAsErrors', true );
		return true;
	}

	/**
	 * Build the database schema based on table structures
	 *
	 * @param bool $force Force rewrite the schemas file
	 *
	 *
	 * @return array|bool|mixed
	 */
	private function build_schema( $force = false ) {
		$schema_file = $this->schema_location . '/db-schema.php';

		/*
		 * We return the data of the existing schema file if it exists and we aren't force re-writing it
		 */
		if ( file_exists( $schema_file ) && ! $force ) {
			return json_decode( file_get_contents( $schema_file ) );
		}

		// Check if we can open the file location for writing
		if ( ! $file = fopen( $schema_file, "w+" ) ) {
			return false;
		}

		$schema = array();

		$tables = $this->get_results( "
			SELECT
				TABLE_NAME
			FROM
				INFORMATION_SCHEMA.TABLES
			WHERE
				TABLE_TYPE = 'BASE TABLE'
			AND
				TABLE_CATALOG = '" . addslashes( DB_NAME ) . "'
		" );
		foreach( $tables AS $table ) {
			$schema[ $table->TABLE_NAME ] = array();

			$columns = $this->get_results( "
				EXEC
					sp_columns
				" . $table->TABLE_NAME . "
			" );
			foreach( $columns AS $column ) {
				$schema[ $table->TABLE_NAME ][ $column->COLUMN_NAME ] = $column;
			}
		}

		fwrite( $file, json_encode( $schema ) );
		fclose( $file );

		return $schema;
	}

	/**
	 * Prepare values based on either the expected schema data (if it exists) or by what type of data it is
	 *
	 * @param string $table
	 * @param string $column
	 * @param mixed  $value
	 *
	 * @return string
	 */
	private function schema_prepare_value( $table, $column, $value ) {
		if ( false === $this->schema || ! isset( $this->schema->$table ) || ! isset( $this->schema->$table->$column ) ) {
			if ( null === $value ) {
				return 'NULL';
			}
			elseif ( ctype_digit( str_replace( array( '.', '-' ), '', $value ) ) && substr_count( $value, '.' ) < 2 ) {
				return $value;
			}
			else {
				return "'" . addslashes( utf8_decode( $value ) ) . "'";
			}
		}

		$schema = $this->schema->$table->$column;
		$numerics = array(
			'int',
			'decimal',
			'money'
		);

		if ( in_array( $schema->TYPE_NAME, $numerics ) ) {
			if ( null === $value || '' === $value ) {
				if ( 1 == $schema->NULLABLE ) {
					return 'NULL';
				}
				else {
					return 0;
				}
			}
			else {
				return $value;
			}
		}
		else {
			if ( null === $value || empty( $value ) ) {
				if ( 1 == $schema->NULLABLE ) {
					return 'NULL';
				}
				else {
					return "''";
				}
			}
		}

		return "'" . addslashes( utf8_decode( $value ) ) . "'";
	}


	/**
	 * Prepare the DB class for a new query
	 *

	 *
	 * @return void
	 */
	private function prepare() {
		$this->error          = array();
		$this->last_insert_id = null;
		$this->last_query     = '';
		$this->num_rows       = 0;
		$this->has_rows       = false;
	}

	/**
	 * Log errors to the error container of the class and to the systems error log
	 *
	 * @param array $errors
	 *

	 *
	 * @return void
	*/
	private function log_error( $errors ) {
		foreach( $errors AS $error ) {
			$new_error = array(
				'DATETIME' => date_time(),
				'SQLSTATE' => $error['SQLSTATE'],
				'code'     => $error['code'],
				'message'  => $error['message'],
				'query'    => $this->last_query
			);

			error_log( "\n\n" . var_export( $new_error, true ), 3, ROOT . "/storage/logs/sql.txt");
			$this->error[] = $new_error;
		}
	}

	/**
	 * Update values in a table that matches the give ncriterias
	 *
	 * @param string $table
	 * @param array  $what
	 * @param array  $where
	 *

	 *
	 * @return void
	 */
	public function update($table, $what, $where, $params ) {
		$set   = '';
		$check = '';

		foreach( $what AS $field => $value ) {
			$field = trim($field);
			$value = trim($value);

			if (!empty($set) ) {
				$set .= ', ';
			}
			$set .= $value . ' = ?';
		}

		foreach( $where AS $field => $value ) {
			$field = trim($field);
			$value = trim($value);

			if (!empty($check) ) {
				$check .= ' AND ';
			}
			$check .= $value . ' = ?';
		}

		$sql = " UPDATE " . $table . " SET " . $set . " WHERE " . $check . "";
		$result = $this->query($sql, $params, false );
	}

	/**
	 * Delete rows in a table based on the given criterias
	 *
	 * @param string $table
	 * @param array  $where
	 *

	 *
	 * @return void
	 */
	public function delete( $table, $where, $params ) {
		$check = '';
		foreach( $where AS $field => $value ) {

			$check .= ' AND ' . $table . '.' . $value;
			$check .= ' = ?';

		}

		$result = $this->query( "
			DELETE FROM
				" . $table . "
			WHERE
				1 = 1
				" . $check . "
		", $params, false );
	}

	/**
	 * Insert a new row and populate it with the given values
	 *
	 * @param string $table
	 * @param array  $data
	 *

	 *
	 * @return void
	 */
	public function insert( $table, $fieldo, $params, $direct = true ) {
		$fields = '';
		$values = '';
		if($direct){
			foreach( $fieldo AS $field => $value ) {

				if ( ! empty( $fields ) ) {
					$fields .= ', ';
					$values .= ', '; 
				}
				$values .= '? ';
				$fields .= $value;
			}
			$result = $this->query( "INSERT INTO " . $table . " ( " . $fields . " ) VALUES ( " . $values . " )", $params, false );
		}else{
			$result = $this->query( false );
		}
	}

	/**
	 * Get a single row from the database and return it in the given format
	 *
	 * @param string $query
	 * @param string $format
	 *

	 *
	 * @return array|bool|null|object
	 */
	public function get_row($query, $where = array(), $format = 'object' ) {
		$request = $this->query( $query , $where );

		if ( ! $this->has_error() ) {
			if ( 'array' == $format ) {
				$response = sqlsrv_fetch_array( $request, SQLSRV_FETCH_ASSOC );
			}
			else {
				$response = sqlsrv_fetch_object( $request );
			}
		}
		else {
			$response = false;
		}

		return $response;
	}

	/**
	 * Get all the rows returned by a query to the database
	 *
	 * @param string $query
	 * @param string $format
	 *

	 *
	 * @return array|bool
	 */
	public function get_results( $query, $where = array(), $format = 'object' ) {
		$response = array();

		$request = $this->query( $query , $where);

		if ( $this->has_error() ) {
			$response = false;
		}
		else {
			if ( 'array' == $format ) {
				while ( $answer = sqlsrv_fetch_array( $request, SQLSRV_FETCH_ASSOC ) ) {
					$response[] = $answer;
				}
			}
			else {
				while ( $answer = sqlsrv_fetch_object( $request ) ) {
					$response[] = $answer;
				}
			}
		}

		return $response;
	}

	/**
	 * Return the primary index value from a table
	 *
	 * @return bool|int
	 */
	public function last_insert_id() {
		if ( $this->has_error() || empty( $this->last_query ) ) {
			return false;
		}

		if ( empty( $this->last_insert_id ) ) {
			$this->last_insert_id = $this->get_row( "SELECT SCOPE_IDENTITY() AS [SCOPE_IDENTITY]" );
		}

		return $this->last_insert_id->SCOPE_IDENTITY;
	}

	/**

	 * @deprecated 0.2.0 Use last_insert_id()
	 * @see last_insert_id()
	 *
	 * @return bool|int
	 */
	public function get_last_id() {
		return $this->last_insert_id();
	}

	/**
	 * Runs the actual query against the database
	 *
	 * @param string $query
	 * @param bool   $can_get_rows
	 *

	 *
	 * @return bool|resource
	 */
	public function query( $query, $where = array(), $can_get_rows = true ) {
		// If no connection is found we try to restore it
		if ( ! $this->is_connected ) {
			$this->is_connected = $this->db_connect();

			// If we couldn't reconnect we break out early
			if ( ! $this->is_connected ) {
				return false;
			}
		}
		$this->prepare();
		$this->last_query = $query;
		$doing_query = sqlsrv_query( $this->conn, $query , $where);
		
		if ( false === $doing_query ) {
			if ( null != ( $errors = sqlsrv_errors() ) ) {
				$this->log_error( $errors );
			}
			throw new Exception(print_r( $this->error, true));
		}
		else {
			$this->has_rows = true;
			$this->num_rows = sqlsrv_num_rows( $doing_query );
		}

		if ( $can_get_rows ) {
			if (sqlsrv_has_rows($doing_query) ) {
				$this->has_rows = true;
			} else {
				$this->has_rows = false;
			}
		}else{
			$rows_affected = sqlsrv_rows_affected( $doing_query );
			if( $rows_affected === false) {
				$this->log_error( $errors );
			} elseif( $rows_affected == -1) {
				  $this->rows_effected = false;
			} else {
				$this->rows_effected = $rows_affected;
			}
		}

		if ( ! empty( $this->error ) ) {
			print_r($this->error);
		}

		return $doing_query;
	}

	/**
	 * Return a list of errors encountered on the last query, or false
	 *
	 * @since 0.2.0
	 *
	 * @return array|bool
	 */
	public function has_error() {
		if ( ! empty( $this->error ) ) {
			return $this->error;
		}

		return false;
	}

	/**

	 * @deprecated 0.2.0 Use has_error() instead
	 * @see has_error()
	 *
	 * @return array|bool
	 */
	public function hasError() {
		return $this->has_error();
	}

	/**
	 * Return the last ran query in its entirety
	 *

	 *
	 * @return string
	 */
	public function get_last_query() {
		return $this->last_query;
	}
}
