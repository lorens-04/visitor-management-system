<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/_bootstrap.php";

api_require_method("POST");
$user = api_require_visitor();
api_revoke_token($conn, (int) $user["access_token_id"]);
api_audit($conn, (int) $user["id"], "mobile.signed_out", "api_access_token", (string) $user["access_token_id"]);
api_success([], 200, "Signed out");

