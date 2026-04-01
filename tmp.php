<?php require 'apache-config/database.php'; print_r(Database::getConnection()->query('SELECT * FROM campaigns')->fetchAll(PDO::FETCH_ASSOC));
