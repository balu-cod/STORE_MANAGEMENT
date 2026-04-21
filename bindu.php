<?php
include 'db_config.php';
date_default_timezone_set('Asia/Kolkata');

$message = "";
$type = "";

// DELETE LOGIC
if (isset($_POST['delete_selected'])) {

    $selected = $_POST['selected'] ?? [];

    if (!empty($selected)) {

        $deleted_time = date("d/m/Y h:i A");
        $user = "admin";

        foreach ($selected as $id) {

            // Backup
            $backup = $conn->prepare("
                INSERT INTO deleted_inventory 
                (original_id, code, qty, loc, last_user, last_updated, last_activity, sap_stock, excel_stock, deleted_by, deleted_at)
                SELECT id, code, qty, loc, last_user, last_updated, last_activity, sap_stock, excel_stock, ?, ?
                FROM inventory
                WHERE id = ?
            ");
            $backup->bind_param("ssi", $user, $deleted_time, $id);
            $backup->execute();

            // Delete
            $delete = $conn->prepare("DELETE FROM inventory WHERE id = ?");
            $delete->bind_param("i", $id);
            $delete->execute();
        }

        $message = "Selected records deleted successfully!";
        $type = "success";
    } else {
        $message = "Please select at least one record!";
        $type = "error";
    }
}

// SEARCH LOGIC
$search = $_GET['search'] ?? "";
$query = "SELECT * FROM inventory";

if (!empty($search)) {
    $search = $conn->real_escape_string($search);
    $query .= " WHERE code LIKE '%$search%' OR loc LIKE '%$search%'";
}

$result = $conn->query($query);
?>

<!DOCTYPE html>
<html>
<head>
<title>Delete Inventory</title>

<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
body {
    font-family: 'Segoe UI';
    background: #f4f7fc;
    padding: 10px;
}

.container {
    max-width: 600px;
    margin: auto;
}

h2 {
    text-align: center;
    margin-bottom: 15px;
}

/* SEARCH */
.search-box {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.search-box input {
    flex: 1;
    padding: 10px;
    border-radius: 8px;
    border: 1px solid #ccc;
}

.search-box button {
    padding: 10px;
    background: #0f62fe;
    color: white;
    border: none;
    border-radius: 8px;
}

/* TABLE */
.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 10px;
    overflow: hidden;
}

th, td {
    padding: 10px;
    font-size: 13px;
    border-bottom: 1px solid #eee;
    text-align: center;
}

th {
    background: #0f62fe;
    color: white;
}

/* BUTTON */
.delete-btn {
    width: 100%;
    margin-top: 15px;
    padding: 12px;
    background: #ff4b5c;
    color: white;
    border: none;
    border-radius: 8px;
}

/* MESSAGE */
.message {
    margin-top: 10px;
    padding: 10px;
    border-radius: 8px;
    text-align: center;
}

.success { background: #d4edda; color: #155724; }
.error { background: #f8d7da; color: #721c24; }

/* MOBILE OPTIMIZATION */
@media(max-width: 480px) {
    th, td {
        font-size: 11px;
        padding: 6px;
    }

    .search-box input {
        font-size: 14px;
    }
}
</style>
</head>

<body>

<div class="container">

<h2>🗑 Delete Inventory</h2>

<!-- SEARCH -->
<form method="GET" class="search-box">
    <input type="text" name="search" placeholder="Search Code / Location" value="<?php echo htmlspecialchars($search); ?>">
    <button type="submit">Search</button>
</form>

<form method="POST">

<div class="table-container">
<table>
<tr>
    <th>Select</th>
    <th>Code</th>
    <th>Qty</th>
    <th>Loc</th>
</tr>

<?php while($row = $result->fetch_assoc()): ?>
<tr>
    <td><input type="checkbox" name="selected[]" value="<?php echo $row['id']; ?>"></td>
    <td><?php echo $row['code']; ?></td>
    <td><?php echo $row['qty']; ?></td>
    <td><?php echo $row['loc']; ?></td>
</tr>
<?php endwhile; ?>

</table>
</div>

<button type="submit" name="delete_selected" class="delete-btn">
    Delete Selected
</button>

</form>

<?php if ($message != ""): ?>
    <div class="message <?php echo $type; ?>">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

</div>

</body>
</html>