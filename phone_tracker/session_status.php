<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/session_bootstrap.php";

if (empty($_SESSION["user_id"]) || empty($_SESSION["role"])) {
    echo json_encode([
        "authenticated" => false,
    ]);
    exit;
}

echo json_encode([
    "authenticated" => true,
    "user_id" => (int) $_SESSION["user_id"],
    "role" => (string) $_SESSION["role"],
    "username" => isset($_SESSION["username"]) ? (string) $_SESSION["username"] : "",
    "display_name" => isset($_SESSION["display_name"]) ? (string) $_SESSION["display_name"] : "",
    "office_code" => isset($_SESSION["office_code"]) ? (string) $_SESSION["office_code"] : "",
]);
