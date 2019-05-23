<?php

namespace cora;

/**
 * This exception is thrown by database->query if the query failed due to a
 * duplicate entry in the database.
 *
 * @author Jon Ziebell
 */
final class DuplicateEntryException extends \Exception {
};

/**
 * This is a MySQLi database wrapper. It provides access to some basic
 * functions like select, insert, and update. Those functions automatically
 * escape table names, column names, and parameters for you using a number of
 * the private functions defined here.
 *
 * Alternatively, you can write your own queries (and use the escape()
 * function to help), and just call query() to run your own.
 *
 * @author Jon Ziebell
 */
final class database extends \mysqli {

  /**
   * The singleton.
   *
   * @var database
   */
  private static $instance;

  /**
   * The second singleton...use sparingly. Used when a second connection to
   * the database is needed to escape a transcation (ex: for writing tokens).
   * It can be used for writing logs as well but that would open up two
   * connections per API call which is bad.
   *
   * @var database
   */
  private static $second_instance;

  /**
   * Whether or not a transaction has been started. Used to make sure only one
   * is started at a time and it gets closed only if it's open.
   *
   * @var bool
   */
  private $transaction_started = false;

  /**
   * The total number of queries executed.
   *
   * @var int
   */
  private $query_count = 0;

  /**
   * The total time all queries have taken to execute.
   *
   * @var float;
   */
  private $query_time = 0;

  /**
   * The cora object.
   *
   * @var cora
   */
  private $cora;

  /**
   * The setting object.
   *
   * @var setting
   */
  private $setting;

  /**
   * Create the mysql object used for the current API call and start a
   * transaction. The same transaction is used for all queries on this
   * connection, even in the case of a multi-api call. The transaction is
   * auto- closed upon destruction of this class.
   *
   * This function is private because this class is a singleton and should be
   * instantiated using the get_instance() function.
   *
   * @throws \Exception If failing to connect to the database.
   */
  public function __construct() {
    $this->cora = cora::get_instance();
    $this->setting = setting::get_instance();

    parent::__construct(
      $this->setting->get('database_host'),
      $this->setting->get('database_username'),
      $this->setting->get('database_password')
    );

    // Have the database driver return ints and floats in PHP native types. PHP
    // does not have a native type for decimals so that gets left behind.
    parent::options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);

    if($this->connect_error !== null) {
      $this->cora->set_error_extra_info(
        [
          'database_error' => $this->connect_error
        ]
      );
      throw new \Exception('Could not connect to database.', 1200);
    }

    $database_name = $this->setting->get('database_name');
    if($database_name !== null) {
      $success = $this->select_db($database_name);
      if($success === false) {

        $this->cora->set_error_extra_info(
          [
            'database_error' => $this->error
          ]
        );
        throw new \Exception('Could not select database.', 1208);
      }
    }
  }

  /**
   * Upon destruction of this class, close the open transaction. I check to
   * make sure one is open, but that should really always be the case since
   * one gets opened regardless.
   */
  public function __destruct() {
    if($this->transaction_started === true) {
      $this->commit_transaction();
    }
  }

  /**
   * Use this function to instantiate this class instead of calling new
   * database() (which isn't allowed anyways). This avoids confusion from
   * trying to use dependency injection by passing an instance of this class
   * around everywhere. It also keeps a single connection open to the database
   * for the current API call.
   *
   * @return database A new database object or the already created one.
   */
  public static function get_instance() {
    if(isset(self::$instance) === false) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Second instance; see comment on the class member declaration.
   *
   * @return database A new database object or the already created one.
   */
  public static function get_second_instance() {
    if(isset(self::$second_instance) === false) {
      self::$second_instance = new self();
    }
    return self::$second_instance;
  }

  /**
   * Start a transaction.
   *
   * @throws \Exception If the transaction fails to start.
   */
  public function start_transaction() {
    if($this->transaction_started === false) {
      $result = $this->query('start transaction');
      if($result === false) {
        throw new \Exception('Failed to start database transaction.', 1201);
      }
      $this->transaction_started = true;
    }
  }

  /**
   * Commit a transaction.
   *
   * @throws \Exception If the transaction fails to commit.
   */
  public function commit_transaction() {
    if($this->transaction_started === true) {
      $this->transaction_started = false;
      $result = $this->query('commit');
      if($result === false) {
        throw new \Exception('Failed to commit database transaction.', 1202);
      }
    }
  }

  /**
   * Rollback the current transaction. This is exposed because the exception
   * handler needs to rollback the current transaction when it runs.
   *
   * @throws \Exception If the transaction fails to rollback.
   */
  public function rollback_transaction() {
    if($this->transaction_started === true) {
      $this->transaction_started = false;
      $result = $this->query('rollback');
      if($result === false) {
        throw new \Exception('Failed to rollback database transaction.', 1203);
      }
    }
  }

  /**
   * Escape a value to be used in a query. Only necessary when doing custom
   * queries. All helper functions like select, insert, and update escape
   * values for you using this function.
   *
   * @param mixed $value The value to escape. Boolean true and false are
   * converted to int 1 and 0 respectively.
   * @param bool $basic If overridden to true, just return real_escape_string
   * of $value. If left alone or set to false, return a value appropriate to
   * be used like "set foo=$bar" as it will have single quotes around it if
   * necessary.
   *
   * @return string The escaped value.
   */
  public function escape($value, $basic = false) {
    if($basic === true) {
      return $this->real_escape_string($value);
    }

    if($value === null) {
      return 'null';
    }
    else if($value === true) {
      return '1';
    }
    else if($value === false) {
      return '0';
    }
    else if(is_int($value) === true || ctype_digit($value) === true) {
      return $value;
    }
    else {
      return '"' . $this->real_escape_string($value) . '"';
    }
  }

  /**
   * Helper function to secure names of tables & columns passed to this class.
   * First of all, these identifiers must be a valid word. Backticks are also
   * placed around the identifier in all cases to allow the use of MySQL
   * keywords as table and column names.
   *
   * @param string $identifier The identifier to escape
   *
   * @throws \Exception If the identifier does not match the character class
   * [A-Za-z0-9_]. That would make it invalid for use in MySQL.
   *
   * @return string The escaped identifier.
   */
  public function escape_identifier($identifier) {
    if(preg_match('/^\w+$/', $identifier) === 1) {
      return '`' . $identifier . '`';
    }
    else {
      $this->cora->set_error_extra_info(
        [
          'identifier' => $identifier
        ]
      );
      throw new \Exception('Query identifier is invalid.', 1204);
    }
  }

  /**
   * Builds a properly escaped string for the 'where column=value' portion of
   * a query.
   *
   * @param string $column The query column.
   * @param mixed $value The value(s) to compare against. You can use null, an
   * array, or any other value here and the appropriate comparison (is null,
   * in, =) will be used.
   *
   * @throws \Exception If an invalid operator was specified.
   *
   * @return string The appropriate escaped string. Examples: `foo` is null
   * `foo` in(1,2,3) `foo`='bar'
   */
  private function column_equals_value_where($column, $value) {
    if($value === null) {
      return $this->escape_identifier($column) . ' is null';
    }
    else if(is_array($value) === true) {
      if(isset($value['operator']) === true) {
        if(in_array($value['operator'], ['>', '<', '=', '>=', '<=', 'between']) === false) {
          throw new \Exception('Invalid operator', 1213);
        }
        if($value['operator'] === 'between') {
          return $this->escape_identifier($column) . ' between ' . $this->escape($value['value'][0]) . ' and ' . $this->escape($value['value'][1]);
        } else {
          return $this->escape_identifier($column) . $value['operator'] . $this->escape($value['value']);
        }
      }
      else {
        return $this->escape_identifier($column) .
          ' in (' . implode(',', array_map([$this, 'escape'], $value)) . ')';
      }
    }
    else {
      return $this->escape_identifier($column) . '=' . $this->escape($value);
    }
  }

  /**
   * Builds a properly escaped string for the 'set column=value' portion of a
   * query.
   *
   * @param string $column The query column.
   * @param mixed $value The value to set.
   *
   * @return string The appropriate escaped string. Examples: `foo`='bar'
   * `foo`=5
   */
  private function column_equals_value_set($column, $value) {
    return $this->escape_identifier($column) . '=' . $this->escape($value);
  }

  /**
   * Performs a query on the database. This function is available publicly for
   * the case when the standard select, insert, and update don't quite cut it.
   *
   * The exceptions are broken up somewhat by type to make it easier to catch
   * and handle these exceptions if desired.
   *
   * This will start a transaction if the query begins with 'insert',
   * 'update', or 'delete' and a transaction has not already been started.
   *
   * IMPORTANT: YOU MUST SANTIZE YOUR OWN DATABASE QUERY WHEN USING THIS
   * FUNCTION DIRECTLY. THIS FUNCTION DOES NOT DO IT FOR YOU.
   *
   * @param string $query The query to execute.
   *
   * @throws DuplicateEntryException if the query failed due to a duplicate
   * entry (unique key violation)
   * @throws \Exception If the query failed and was not caught by any other
   * exception types.
   *
   * @return mixed The result directly from $mysqli->query.
   */
  public function query($query, $resultmode = NULL) {
    // If this was an insert, update or delete, start a transaction
    $query_type = substr(trim($query), 0, 6);
    if(
      in_array($query_type, ['insert', 'update', 'delete']) === true &&
      $this->setting->get('use_transactions') === true
    ) {
      $this->start_transaction();
    }

    $start = microtime(true);
    $result = parent::query($query);
    $stop = microtime(true);

    if($result === false) {
      $database_error = $this->error;
      $this->rollback_transaction();

      $this->cora->set_error_extra_info(
        [
          'database_error' => $database_error,
          'query' => $query
        ]
      );

      if(stripos($database_error, 'duplicate entry') !== false) {
        throw new DuplicateEntryException('Duplicate database entry.', 1205);
      }
      else {
        throw new \Exception('Database query failed.', 1206);
      }
    }

    // Don't log info about transactions...they're a wash
    $this->query_count++;
    $this->query_time += ($stop - $start);

    return $result;
  }

  /**
   * Select some columns from some table with some where clause.
   *
   * @param string $resource The resource to select from.
   * @param array $attributes An array of key value pairs to search by and can
   * include arrays if you want to search in() something.
   * @param array $columns The columns to return. If not specified, all
   * columns are returned.
   *
   * @return array An array of the database rows with the specified columns.
   * Even a single result will still be returned in an array of size 1.
   */
  public function read($resource, $attributes = [], $columns = []) {
    $table = $this->get_table($resource);

    // Build the column listing.
    if(count($columns) === 0) {
      $columns = '*';
    }
    else {
      $columns = implode(
        ',',
        array_map([$this, 'escape_identifier'], $columns)
      );
    }

    // Remove empty arrays from the attributes to avoid query errors on the
    // empty in() statement.
    foreach($attributes as $key => $value) {
      if(is_array($value) === true && count($value) === 0) {
        unset($attributes[$key]);
      }
    }

    // Build the where clause.
    if(count($attributes) === 0) {
      $where = '';
    }
    else {
      $where = ' where ' .
        implode(
          ' and ',
          array_map(
            [$this, 'column_equals_value_where'],
            array_keys($attributes),
            $attributes
          )
        );
    }

    // Put everything together and return the result.
    $query = 'select ' . $columns . ' from ' .
      $this->escape_identifier($table) . $where;
    $result = $this->query($query);

    /**
     * Get a list of all fields that need to be cast to other data types. The
     * MYSQLI_OPT_INT_AND_FLOAT_NATIVE flag handles ints and floats. This
     * turns decimal into float and tinyint(1) into boolean.
     */
    $float_fields = [];
    $boolean_fields = [];
    $json_fields = [];
    while($field_info = $result->fetch_field()) {
      if($field_info->type === 1 && $field_info->length === 1) {
        $boolean_fields[] = $field_info->name;
      }
      else if($field_info->type === 246) {
        $float_fields[] = $field_info->name;
      }
      else if($field_info->type === 245) {
        $json_fields[] = $field_info->name;
      }
      else if(substr($field_info->name, 0, 5) === 'json_') {
        // TODO This will go away as soon as I switch to json type columns.
        $json_fields[] = $field_info->name;
      }
    }

    $results = [];
    while($row = $result->fetch_assoc()) {
      // Cast if necessary.
      foreach($float_fields as $float_field) {
        $row[$float_field] = (float) $row[$float_field];
      }
      foreach($boolean_fields as $boolean_field) {
        $row[$boolean_field] = (boolean) $row[$boolean_field];
      }
      foreach($json_fields as $json_field) {
        $row[$json_field] = json_decode($row[$json_field], true);
      }

      // Diverge the converged column.
      if(
        isset($row['converged']) === true &&
        class_exists($resource) === true && // This will also call the autoloader to make sure it's loaded
        isset($resource::$converged) === true
      ) {
        foreach($resource::$converged as $column => $column_properties) {
          if(isset($row[$column]) === true) {
            throw new \Exception('Column `' . $column . '` exists; cannot be overwritten by converged column.', 1212);
          }
          $row[$column] = (isset($row['converged'][$column]) === true) ? $row['converged'][$column] : null;
        }
        unset($row['converged']);
      }

      $results[] = $row;
    }

    return $results;
  }

  /**
   * Converge attributes.
   *
   * @param string $resource
   * @param int $id
   * @param array $attributes
   *
   * @return array
   */
  private function converge_attributes($resource, $id, $attributes) {
    if(
      class_exists($resource) === true && // This will also call the autoloader to make sure it's loaded
      isset($resource::$converged) === true &&
      empty($resource::$converged) === false
    ) {
      $table = $this->get_table($resource);

      if($id !== null) {
        $existing_attributes = [];
        $existing_attributes[$table . '_id'] = $id;
        $existing_row = $this->read($resource, $existing_attributes)[0];
      }
      else {
        $existing_row = [];
      }

      $converged = [];
      foreach($resource::$converged as $column => $column_properties) {
        if(isset($existing_row[$column]) === true) {
          $converged[$column] = $existing_row[$column];
        }
        if(array_key_exists($column, $attributes) === true) {
          if($attributes[$column] === null) {
            unset($converged[$column]);
          }
          else {
            switch($column_properties['type']) {
              case 'int':
              case 'float':
              case 'string':
                settype($attributes[$column], $column_properties['type']);
              break;
            }
            $converged[$column] = $attributes[$column];
          }
          unset($attributes[$column]);
        }
      }
      $attributes['converged'] = json_encode($converged);
    }

    return $attributes;
  }

  /**
   * Update some columns in a table by the primary key. Doing updates without
   * using the primary key are supported by writing your own queries and using
   * the database->query() function. That should be a rare circumstance
   * though.
   *
   * @param string $resource The resource to update.
   * @param array $attributes The attributes to set.
   *
   * @throws \Exception If no attributes were specified.
   *
   * @return int The updated row.
   */
  public function update($resource, $attributes) {
    $table = $this->get_table($resource);

    // TODO This will go away as soon as I switch to json type columns.
    foreach($attributes as $key => $value) {
      if(substr($key, 0, 5) === 'json_') {
        if($value === null) {
          $attributes[$key] = null;
        }
        else {
          $attributes[$key] = json_encode($value);
        }
      }
    }

    // Check for errors
    if(isset($attributes[$table . '_id']) === false) {
      throw new \Exception('ID is required for update.', 1214);
    }

    // Extract the ID.
    $id = $attributes[$table . '_id'];
    unset($attributes[$table . '_id']);

    // Check for errors
    if(count($attributes) === 0) {
      throw new \Exception('Updates require at least one attribute.', 1207);
    }

    // Converge the diverged attributes.
    $attributes = $this->converge_attributes($resource, $id, $attributes);

    // Build the column setting
    $columns = implode(
      ',',
      array_map(
        [$this, 'column_equals_value_set'],
        array_keys($attributes),
        $attributes
      )
    );

    // Build the where clause
    $where_attributes = [$table . '_id' => $id];
    $where = 'where ' .
      implode(
        ' and ',
        array_map(
          [$this, 'column_equals_value_where'],
          array_keys($where_attributes),
          $where_attributes
        )
      );

    $query = 'update ' . $this->escape_identifier($table) .
      ' set ' . $columns . ' ' . $where;

    // Disallow updates in the demo.
    if($this->setting->is_demo() === true) {
      return $this->read($resource, $where_attributes)[0];
    }

    $this->query($query);

    return $this->read($resource, $where_attributes)[0];
  }

  /**
   * Actually delete a row from a table by the primary key.
   *
   * @param string $table The table to delete from.
   * @param int $id The value of the primary key to delete.
   *
   * @return int The number of rows affected by the delete (could be 0).
   */
  public function delete($table, $id) {
    $query = 'delete from ' . $this->escape_identifier($table) .
      ' where ' . $this->escape_identifier($table . '_id') . ' = ' .
      $this->escape($id);
    $this->query($query);

    return $this->affected_rows;
  }

  /**
   * Insert a row into the specified table. This only supports single-row
   * inserts.
   *
   * @param string $table The table to insert into.
   * @param array $attributes The attributes to set on the row
   *
   * @return int The primary key of the inserted row.
   */
  public function create($resource, $attributes) {
    $table = $this->get_table($resource);

    // TODO This will go away as soon as I switch to json type columns.
    foreach($attributes as $key => $value) {
      if(substr($key, 0, 5) === 'json_') {
        if($value === null) {
          $attributes[$key] = null;
        }
        else {
          $attributes[$key] = json_encode($value);
        }
      }
    }

    $attributes = $this->converge_attributes($resource, null, $attributes);

    $columns = implode(
      ',',
      array_map([$this, 'escape_identifier'], array_keys($attributes))
    );

    $values = implode(
      ',',
      array_map([$this, 'escape'], $attributes)
    );

    $query =
      'insert into ' . $this->escape_identifier($table) .
      '(' . $columns . ') values (' . $values . ')';

    $this->query($query);

    $read_attributes = [];
    $read_attributes[$table . '_id'] = $this->insert_id;
    return $this->read($resource, $read_attributes)[0];
    // return $this->insert_id;
  }

  /**
   * Gets the number of queries that have been executed.
   *
   * @return int The query count.
   */
  public function get_query_count() {
    return $this->query_count;
  }

  /**
   * Gets the time taken to execute all of the queries.
   *
   * @return float The total execution time.
   */
  public function get_query_time() {
    return $this->query_time;
  }

  /**
   * Turn a resource into a table.
   *
   * @param string $resource
   *
   * @return string
   */
  private function get_table($resource) {
    $class_parts = explode('\\', $resource);
    return end($class_parts);
  }

  /**
   * Attempt to get a database lock.
   *
   * @param string $lock_name The lock name.
   * @param int $timeout How long to wait for the lock. Negative values are
   * forever.
   *
   * @throws \Exception If the lock could not be obtained.
   */
  public function get_lock($lock_name, $timeout = 0) {
    $result = $this->query('
      select
        get_lock(' .
          $this->escape($this->setting->get('database_name') . '_' . $lock_name) . ', ' .
          $this->escape($timeout) .
        ') `lock`
      ');
    $row = $result->fetch_assoc();
    if($row['lock'] !== 1) {
      throw new \Exception('Could not get lock.', 1209);
    }
  }

  /**
   * Attempt to release a database lock.
   *
   * @param string $lock_name The lock name.
   *
   * @throws \Exception If the lock was not established by this thread.
   * @throws \Exception If the lock does not exist.
   */
  public function release_lock($lock_name) {
    $result = $this->query('
      select
        release_lock(' . $this->escape($this->setting->get('database_name') . '_' . $lock_name) . ') `lock`
      ');
    $row = $result->fetch_assoc();
    if($row['lock'] === 0) {
      throw new \Exception('Lock not established by this thread.', 1210);
    }
    else if($row['lock'] === null) {
      throw new \Exception('Lock does not exist.', 1211);
    }
  }

  /**
   * Set the time zone. Useful for querying in a certain time zone so MySQL
   * will just handle the conversion.
   *
   * @param int $time_zone_offset Offset in minutes.
   */
  public function set_time_zone($time_zone_offset) {
    // $time_zone_offset = -360;
    $operator = $time_zone_offset < 0 ? '-' : '+';
    $time_zone_offset = abs($time_zone_offset);
    $offset_hours = floor($time_zone_offset / 60);
    $offset_minutes = $time_zone_offset % 60;
    // var_dump($offset_hours);
    // var_dump($offset_minutes);
    // var_dump('SET time_zone = "' . $operator . sprintf('%d', $offset_hours) . ':' . str_pad($offset_minutes, 2, STR_PAD_LEFT) . '"');
    // die();
    $this->query('SET time_zone = "' . $operator . sprintf('%d', $offset_hours) . ':' . str_pad($offset_minutes, 2, STR_PAD_LEFT) . '"');
  }

}
