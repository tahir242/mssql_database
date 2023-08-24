# PHP MSSQL Database Class (Database.php)

The PHP Database Class is a utility for simplifying database operations in PHP applications. It provides an object-oriented interface for connecting to a database, executing queries, and performing CRUD (Create, Read, Update, Delete) operations on database records. This class is designed to work with MSSQL databases and offers methods for executing various types of SQL queries.

## Features

- Connect to a MSSQL database.
- Execute SELECT, INSERT, UPDATE, and DELETE queries.
- Retrieve single rows or multiple results.
- Handle query parameters and data binding.

## Installation

1. Clone this repository or download the `Database.php` file.
2. Include the class in your PHP project:

```php
require_once 'path/to/Database.php';
```

## Usage

## DB Connection

```php
$db = new Database($dbuser, $dbpass, $dbname, $dbhost);

// OR use a helper function
function db() {
    global $db;
    return $db;
}
```

## SELECT Single Row

```php
$query  = "SELECT * FROM [users] WHERE UserID = ? AND Active = ?";
$params = array((int)$userid, 1);

// Get as object
$user = db()->get_row($query, $params);
echo $user->Username;
echo $user->Email;

// OR get as an associative array
$user = db()->get_row($query, $params, "array");
echo $user['Username'];
echo $user['Email'];
```

## SELECT Multiple Rows

```php
$query = "SELECT * FROM [users] WHERE Active = ?";
$params = array(1);

$users = db()->get_results($query, $params);
foreach ($users as $user) {
    echo $user->Username;
    echo $user->Email;
}
```
## INSERT Query

```php
$table = "User";
$fields = array("Username", "Email");
$params = array($data['Username'], $data['Email']);
$db->insert($table, $fields, $params);
```

## UPDATE Query

```php
$what   = array("Username", "Email");
$where  = array("UserID");
$params = array($data['Username'], $data['Email'], $data['UserID']);
$db->update("User", $what, $where, $params);
```

## DELETE Query

```php
$table   = "User";
$where   = array("UserID");
$params  = array($id);
$deleted = $db->delete($table, $where, $params);
if ($deleted) {
    echo "Record deleted successfully.";
} else {
    echo "Deletion failed.";
}
```

## Important Note
This class working with MSSQL databases. Make sure to handle database connections, credentials, and security properly in your application.

## License
This project is licensed under the MIT License.
