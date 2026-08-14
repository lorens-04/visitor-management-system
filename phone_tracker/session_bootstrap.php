<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login_json(): void
{
    header("Content-Type: application/json; charset=utf-8");
    if (empty($_SESSION["user_id"]) || empty($_SESSION["role"])) {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Not authenticated"]);
        exit;
    }
}

function require_admin_json(): void
{
    require_login_json();
    if ($_SESSION["role"] !== "admin") {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden"]);
        exit;
    }
}

/**
 * @param string[] $roles
 */
function require_roles_json(array $roles): void
{
    require_login_json();
    if (!in_array($_SESSION["role"], $roles, true)) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden"]);
        exit;
    }
}
