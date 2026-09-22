<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/db.php";

require_roles_json(["offices", "security", "admin"]);

$userId = (int) $_SESSION["user_id"];

function staff_profile_payload(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, username, display_name, role, office_code, profile_image
         FROM app_users WHERE id = ? AND is_active = 1 LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    return [
        "id" => (int) $row["id"],
        "username" => (string) $row["username"],
        "display_name" => (string) $row["display_name"],
        "role" => (string) $row["role"],
        "office_code" => (string) $row["office_code"],
        "profile_image_url" => $row["profile_image"] ? (string) $row["profile_image"] : "",
    ];
}

function staff_profile_delete_image(string $relativePath): void
{
    if (!preg_match('#^uploads/profile_pictures/[a-f0-9]{32}\.(?:jpg|png|webp)$#', $relativePath)) {
        return;
    }
    $uploadRoot = realpath(__DIR__ . "/uploads/profile_pictures");
    $filePath = realpath(__DIR__ . "/" . str_replace("/", DIRECTORY_SEPARATOR, $relativePath));
    if ($uploadRoot && $filePath && dirname($filePath) === $uploadRoot && is_file($filePath)) {
        @unlink($filePath);
    }
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $profile = staff_profile_payload($conn, $userId);
    $conn->close();
    echo json_encode(["success" => (bool) $profile, "profile" => $profile]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $conn->close();
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$displayName = trim((string) ($_POST["display_name"] ?? ""));
$removePhoto = (string) ($_POST["remove_photo"] ?? "") === "1";
if (strlen($displayName) < 2 || strlen($displayName) > 100) {
    $conn->close();
    echo json_encode(["success" => false, "message" => "Display name must be between 2 and 100 characters"]);
    exit;
}

$current = staff_profile_payload($conn, $userId);
if (!$current) {
    $conn->close();
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Profile not found"]);
    exit;
}

$oldImage = (string) ($current["profile_image_url"] ?? "");
$newImage = $removePhoto ? "" : $oldImage;
$newFilePath = "";
$upload = $_FILES["profile_image"] ?? null;

if (is_array($upload) && (int) ($upload["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if ((int) $upload["error"] !== UPLOAD_ERR_OK) {
        $conn->close();
        echo json_encode(["success" => false, "message" => "The profile photo could not be uploaded"]);
        exit;
    }
    if ((int) $upload["size"] <= 0 || (int) $upload["size"] > 2 * 1024 * 1024) {
        $conn->close();
        echo json_encode(["success" => false, "message" => "Profile photo must be 2 MB or smaller"]);
        exit;
    }

    $imageInfo = @getimagesize((string) $upload["tmp_name"]);
    $mime = is_array($imageInfo) ? (string) ($imageInfo["mime"] ?? "") : "";
    $extensions = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
    if (!isset($extensions[$mime]) || (int) ($imageInfo[0] ?? 0) < 1 || (int) ($imageInfo[1] ?? 0) < 1
        || (int) $imageInfo[0] > 5000 || (int) $imageInfo[1] > 5000) {
        $conn->close();
        echo json_encode(["success" => false, "message" => "Use a valid JPG, PNG, or WebP image up to 5000 × 5000 pixels"]);
        exit;
    }

    $uploadDirectory = __DIR__ . "/uploads/profile_pictures";
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
        $conn->close();
        echo json_encode(["success" => false, "message" => "The profile-photo folder is unavailable"]);
        exit;
    }
    $filename = bin2hex(random_bytes(16)) . "." . $extensions[$mime];
    $newFilePath = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string) $upload["tmp_name"], $newFilePath)) {
        $conn->close();
        echo json_encode(["success" => false, "message" => "The profile photo could not be saved"]);
        exit;
    }
    $newImage = "uploads/profile_pictures/" . $filename;
}

$imageValue = $newImage !== "" ? $newImage : null;
$update = $conn->prepare("UPDATE app_users SET display_name = ?, profile_image = ? WHERE id = ? AND is_active = 1");
if (!$update) {
    if ($newFilePath !== "") {
        @unlink($newFilePath);
    }
    $conn->close();
    echo json_encode(["success" => false, "message" => "Could not update the profile"]);
    exit;
}
$update->bind_param("ssi", $displayName, $imageValue, $userId);
$saved = $update->execute();
$update->close();
if (!$saved) {
    if ($newFilePath !== "") {
        @unlink($newFilePath);
    }
    $conn->close();
    echo json_encode(["success" => false, "message" => "Could not update the profile"]);
    exit;
}

$_SESSION["display_name"] = $displayName;
if ($oldImage !== "" && $oldImage !== $newImage) {
    staff_profile_delete_image($oldImage);
}

$audit = $conn->prepare(
    "INSERT INTO audit_logs
     (actor_user_id, action, entity_type, entity_id, details_json, ip_address)
     VALUES (?, 'user.profile_updated', 'app_user', ?, ?, ?)"
);
if ($audit) {
    $entityId = (string) $userId;
    $details = json_encode([
        "display_name_changed" => $displayName !== (string) $current["display_name"],
        "profile_image_changed" => $oldImage !== $newImage,
    ]);
    $ipAddress = substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
    $audit->bind_param("isss", $userId, $entityId, $details, $ipAddress);
    $audit->execute();
    $audit->close();
}

$profile = staff_profile_payload($conn, $userId);
$conn->close();
echo json_encode([
    "success" => true,
    "message" => "Profile updated",
    "profile" => $profile,
]);
