(function () {
    if (!PhoneTrackerAuth.requireRole(["security", "admin"])) {
        return;
    }

    const role = PhoneTrackerAuth.getRole();
    const displayName = PhoneTrackerAuth.getDisplayName() || (role === "admin" ? "Administrator" : "Security Desk");
    const username = PhoneTrackerAuth.getUsername() || (role === "admin" ? "admin" : "security");
    const profileButton = document.getElementById("securityProfileBtn");
    const profileMenu = document.getElementById("securityProfileMenu");

    document.getElementById("securityProfileName").textContent = displayName;
    document.getElementById("securityProfileUsername").textContent = username;
    document.getElementById("securityRoleLabel").textContent = role === "admin" ? "Admin" : "Security";
    document.getElementById("securityAdminLink").hidden = role !== "admin";

    profileButton.addEventListener("click", function () {
        const willOpen = profileMenu.hidden;
        profileMenu.hidden = !willOpen;
        profileButton.setAttribute("aria-expanded", String(willOpen));
    });

    document.addEventListener("click", function (event) {
        if (!profileMenu.hidden && !profileMenu.contains(event.target) && !profileButton.contains(event.target)) {
            profileMenu.hidden = true;
            profileButton.setAttribute("aria-expanded", "false");
        }
    });

    document.getElementById("logoutBtn").addEventListener("click", function () {
        PhoneTrackerAuth.logout().then(function () {
            window.location.href = "login.html?v=20260917-3";
        });
    });

    const readerId = "reader";
    const cameraMessage = document.getElementById("cameraMessage");
    const cameraState = document.getElementById("cameraState");
    const cameraStateText = cameraState.querySelector("span");
    const cameraPlaceholder = document.getElementById("cameraPlaceholder");
    const cameraPlaceholderTitle = document.getElementById("cameraPlaceholderTitle");
    const cameraPlaceholderText = document.getElementById("cameraPlaceholderText");
    const scannerGuide = document.querySelector(".security-scanner-guide");
    const cameraSelectField = document.getElementById("cameraSelectField");
    const cameraSelect = document.getElementById("cameraSelect");
    const retryCameraBtn = document.getElementById("retryCameraBtn");
    const scanQrPhotoBtn = document.getElementById("scanQrPhotoBtn");
    const qrPhotoInput = document.getElementById("qrPhotoInput");
    const chooseQrImageBtn = document.getElementById("chooseQrImageBtn");
    const qrImageInput = document.getElementById("qrImageInput");
    const manualEntry = document.getElementById("manualEntry");
    const manualForm = document.getElementById("manualForm");
    const tokenInput = document.getElementById("tokenInput");
    const submitTokenBtn = document.getElementById("submitTokenBtn");
    const scanResult = document.getElementById("scanResult");
    const scanResultLabel = document.getElementById("scanResultLabel");
    const scanResultTitle = document.getElementById("scanResultTitle");
    const scanResultText = document.getElementById("scanResultText");
    const scanResultDetails = document.getElementById("scanResultDetails");
    const scanVisitorName = document.getElementById("scanVisitorName");
    const scanDestination = document.getElementById("scanDestination");
    const openOverrideBtn = document.getElementById("openQrOverrideBtn");
    const scanNextBtn = document.getElementById("scanNextBtn");
    const overrideDialog = document.getElementById("qrOverrideDialog");
    const overrideForm = document.getElementById("qrOverrideForm");
    const overrideReason = document.getElementById("qrOverrideReason");
    const overrideMinutes = document.getElementById("qrOverrideMinutes");
    const overrideError = document.getElementById("qrOverrideError");
    const confirmOverrideBtn = document.getElementById("confirmQrOverrideBtn");

    let scanner = null;
    let scannerRunning = false;
    let scannerPaused = false;
    let scanLocked = false;
    let nextScanTimer = 0;
    let lastSentToken = "";
    let lastSentAt = 0;
    let pendingOverride = null;
    let photoMode = false;

    function canUseLiveCamera() {
        return window.isSecureContext || ["localhost", "127.0.0.1", "::1"].includes(window.location.hostname);
    }

    function setImageScanDisabled(disabled) {
        scanQrPhotoBtn.disabled = disabled;
        chooseQrImageBtn.disabled = disabled;
    }

    function normalizeToken(raw) {
        if (!raw || typeof raw !== "string") {
            return "";
        }

        const value = raw.trim();
        const tokenMatch = value.match(/[?&]token=([a-f0-9]{64})(?:&|$)/i);
        if (tokenMatch) {
            return tokenMatch[1].toLowerCase();
        }

        const hexOnly = value.replace(/[^a-f0-9]/gi, "");
        return hexOnly.length === 64 ? hexOnly.toLowerCase() : "";
    }

    function setCameraStatus(state, statusText, message, placeholderTitle, placeholderText) {
        cameraState.className = "security-camera-state is-" + state;
        cameraStateText.textContent = statusText;
        cameraMessage.textContent = message;
        cameraPlaceholderTitle.textContent = placeholderTitle || statusText;
        cameraPlaceholderText.textContent = placeholderText || message;

        const isScanning = state === "active";
        cameraPlaceholder.hidden = isScanning;
        scannerGuide.hidden = !isScanning;
        retryCameraBtn.hidden = state !== "error";
    }

    function showPhotoReady() {
        photoMode = true;
        setCameraStatus(
            "ready",
            "Phone camera",
            "Tap the button below to photograph the visitor QR code.",
            "Ready to scan",
            "The QR photo is decoded on this device, then securely checked by the server."
        );
        retryCameraBtn.hidden = !canUseLiveCamera();
        setImageScanDisabled(false);
    }

    function showReadyResult() {
        scanResult.className = "security-scan-result is-ready";
        scanResultLabel.textContent = "Scanner ready";
        scanResultTitle.textContent = "Waiting for a visitor pass";
        scanResultText.textContent = "A successful scan will show the visitor details here.";
        scanResultDetails.hidden = true;
        openOverrideBtn.hidden = true;
        scanNextBtn.hidden = true;
        pendingOverride = null;
    }

    function showProcessingResult() {
        scanResult.className = "security-scan-result is-processing";
        scanResultLabel.textContent = "Checking pass";
        scanResultTitle.textContent = "Please wait...";
        scanResultText.textContent = "Verifying the visitor appointment and check-in status.";
        scanResultDetails.hidden = true;
        openOverrideBtn.hidden = true;
        scanNextBtn.hidden = true;
    }

    function formatSchedule(value) {
        if (!value) {
            return "Schedule unavailable";
        }
        const date = new Date(String(value).replace(" ", "T"));
        if (Number.isNaN(date.getTime())) {
            return String(value);
        }
        return new Intl.DateTimeFormat("en-PH", {
            month: "short",
            day: "numeric",
            year: "numeric",
            hour: "numeric",
            minute: "2-digit",
        }).format(date);
    }

    function showErrorResult(message, data) {
        scanResult.className = "security-scan-result is-error";
        scanResultLabel.textContent = "Pass not accepted";
        scanResultTitle.textContent = "Check-in unsuccessful";
        scanResultText.textContent = message || "The visitor pass could not be verified.";
        const canOverride = Boolean(data && data.can_override && data.appointment_id);
        pendingOverride = canOverride ? { token: lastSentToken, appointment: data } : null;
        scanResultDetails.hidden = !canOverride;
        openOverrideBtn.hidden = !canOverride;
        scanNextBtn.hidden = !canOverride;
        if (canOverride) {
            scanVisitorName.textContent = data.visitor_full_name || "Visitor";
            scanDestination.textContent = data.office_label || "Not specified";
        }
    }

    function showSuccessResult(data) {
        const alreadyCheckedIn = Boolean(data.already_checked_in);
        scanResult.className = "security-scan-result is-success";
        scanResultLabel.textContent = alreadyCheckedIn ? "Already checked in" : "Check-in complete";
        scanResultTitle.textContent = alreadyCheckedIn ? "This pass was already used" : "Visitor checked in successfully";
        scanResultText.textContent = alreadyCheckedIn
            ? "No duplicate check-in was created."
            : "The visitor can now proceed to the listed destination.";
        scanVisitorName.textContent = data.visitor_full_name || "Visitor";
        scanDestination.textContent = data.office_label || "Not specified";
        scanResultDetails.hidden = false;
        openOverrideBtn.hidden = true;
        scanNextBtn.hidden = false;
        pendingOverride = null;
    }

    function pauseScanner() {
        if (!scannerRunning || !scanner || typeof scanner.pause !== "function") {
            return;
        }

        try {
            scanner.pause(true);
            scannerPaused = true;
        } catch (error) {}
    }

    function prepareNextScan() {
        window.clearTimeout(nextScanTimer);
        scanLocked = false;
        submitTokenBtn.disabled = false;
        setImageScanDisabled(false);
        tokenInput.value = "";
        showReadyResult();

        if (photoMode) {
            showPhotoReady();
            return;
        }

        if (scannerPaused && scanner && typeof scanner.resume === "function") {
            try {
                scanner.resume();
                scannerPaused = false;
            } catch (error) {
                loadCameras();
            }
        }
    }

    function checkIn(rawToken) {
        if (scanLocked) {
            return;
        }

        const token = normalizeToken(rawToken);
        if (!token) {
            setImageScanDisabled(false);
            showErrorResult("That visitor pass code is invalid or incomplete. Ask the visitor to reopen their QR pass and try again.", null);
            manualEntry.open = true;
            tokenInput.focus();
            return;
        }

        const now = Date.now();
        if (token === lastSentToken && now - lastSentAt < 4000) {
            return;
        }

        lastSentToken = token;
        lastSentAt = now;
        scanLocked = true;
        submitTokenBtn.disabled = true;
        setImageScanDisabled(true);
        showProcessingResult();

        fetch("scan_appointment.php", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ token: token }),
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    submitTokenBtn.disabled = false;
                    setImageScanDisabled(false);
                    if (data && data.can_override) {
                        pauseScanner();
                        scanLocked = true;
                    } else {
                        scanLocked = false;
                    }
                    showErrorResult((data && data.message) || "The visitor pass could not be checked in.", data);
                    return;
                }

                pauseScanner();
                showSuccessResult(data);
                nextScanTimer = window.setTimeout(prepareNextScan, 6000);
            })
            .catch(function () {
                scanLocked = false;
                submitTokenBtn.disabled = false;
                setImageScanDisabled(false);
                showErrorResult("Could not reach the check-in server. Check the connection and try again.", null);
            });
    }

    function openOverrideDialog() {
        if (!pendingOverride) {
            return;
        }
        const data = pendingOverride.appointment;
        document.getElementById("overrideVisitorName").textContent = data.visitor_full_name || "Visitor";
        document.getElementById("overrideDestination").textContent = data.office_label || "Not specified";
        document.getElementById("overrideSchedule").textContent = formatSchedule(data.scheduled_start_at) + " – " + formatSchedule(data.scheduled_end_at);
        overrideReason.value = "";
        overrideMinutes.value = "30";
        overrideError.textContent = "";
        overrideError.hidden = true;
        overrideDialog.showModal();
        window.setTimeout(function () { overrideReason.focus(); }, 50);
    }

    function closeOverrideDialog() {
        overrideDialog.close();
        overrideError.textContent = "";
        overrideError.hidden = true;
    }

    function cameraPriority(camera) {
        const label = camera && camera.label ? camera.label.toLowerCase() : "";
        if (label.includes("back") || label.includes("rear") || label.includes("environment")) {
            return 0;
        }
        if (label.includes("front") || label.includes("user") || label.includes("facetime")) {
            return 2;
        }
        return 1;
    }

    function stopScanner() {
        if (!scanner || !scannerRunning) {
            return Promise.resolve();
        }

        if (scannerPaused && typeof scanner.resume === "function") {
            try {
                scanner.resume();
            } catch (error) {}
            scannerPaused = false;
        }

        return scanner.stop()
            .catch(function () {})
            .then(function () {
                scannerRunning = false;
            });
    }

    function startScanner(cameraId) {
        photoMode = false;
        cameraSelect.disabled = true;
        retryCameraBtn.disabled = true;
        setCameraStatus(
            "loading",
            "Starting",
            "Opening the selected camera...",
            "Opening camera",
            "This usually takes only a moment."
        );

        if (!scanner) {
            scanner = new Html5Qrcode(readerId);
        }

        return stopScanner()
            .then(function () {
                return scanner.start(
                    cameraId,
                    { fps: 10, qrbox: { width: 250, height: 250 } },
                    function (decodedText) {
                        checkIn(decodedText);
                    },
                    function () {}
                );
            })
            .then(function () {
                scannerRunning = true;
                cameraSelect.disabled = false;
                retryCameraBtn.disabled = false;
                setCameraStatus(
                    "active",
                    "Scanning",
                    "Camera is active and ready to scan.",
                    "Camera active",
                    "Place the visitor QR code inside the frame."
                );
            })
            .catch(function () {
                scannerRunning = false;
                cameraSelect.disabled = false;
                retryCameraBtn.disabled = false;
                manualEntry.open = true;
                setCameraStatus(
                    "error",
                    "Camera blocked",
                    "Camera permission was denied or the camera is unavailable.",
                    "Camera access needed",
                    "Allow camera access in your browser, then try again."
                );
            });
    }

    function loadCameras() {
        if (typeof Html5Qrcode === "undefined") {
            setImageScanDisabled(true);
            manualEntry.open = true;
            setCameraStatus(
                "error",
                "Scanner unavailable",
                "The camera scanner could not be loaded.",
                "Scanner unavailable",
                "Use manual entry or check the internet connection."
            );
            return;
        }

        if (!canUseLiveCamera()) {
            cameraSelectField.hidden = true;
            manualEntry.open = false;
            showPhotoReady();
            return;
        }

        retryCameraBtn.disabled = true;
        setCameraStatus(
            "loading",
            "Starting",
            "Requesting camera access...",
            "Connecting to camera",
            "Allow camera access when your browser asks."
        );

        Html5Qrcode.getCameras()
            .then(function (devices) {
                if (!devices || devices.length === 0) {
                    throw new Error("No camera found");
                }

                const cameras = devices.slice().sort(function (a, b) {
                    return cameraPriority(a) - cameraPriority(b);
                });

                cameraSelect.innerHTML = "";
                cameras.forEach(function (camera, index) {
                    const option = document.createElement("option");
                    option.value = camera.id;
                    option.textContent = camera.label || "Camera " + (index + 1);
                    cameraSelect.appendChild(option);
                });

                cameraSelectField.hidden = cameras.length < 2;
                cameraSelect.value = cameras[0].id;
                return startScanner(cameras[0].id);
            })
            .catch(function () {
                retryCameraBtn.disabled = false;
                manualEntry.open = true;
                setCameraStatus(
                    "error",
                    "Camera blocked",
                    "Camera permission was denied or no camera is available.",
                    "Camera access needed",
                    "Allow camera access in your browser, then try again."
                );
            });
    }

    function scanQrPhoto(file) {
        if (!file || scanLocked || typeof Html5Qrcode === "undefined") {
            return;
        }

        if (!String(file.type || "").startsWith("image/")) {
            showErrorResult("Choose a photo or screenshot containing the visitor QR code.", null);
            return;
        }
        if (file.size > 20 * 1024 * 1024) {
            showErrorResult("That image is too large. Choose a QR image smaller than 20 MB.", null);
            return;
        }

        photoMode = true;
        setImageScanDisabled(true);
        setCameraStatus(
            "loading",
            "Reading QR",
            "Reading the QR code from the photo...",
            "Reading QR code",
            "Keep this page open for a moment."
        );

        if (!scanner) {
            scanner = new Html5Qrcode(readerId);
        }

        stopScanner()
            .then(function () {
                if (typeof scanner.scanFileV2 === "function") {
                    return scanner.scanFileV2(file, true).then(function (result) {
                        return result && result.decodedText ? result.decodedText : "";
                    });
                }
                return scanner.scanFile(file, true);
            })
            .then(function (decodedText) {
                if (!decodedText) {
                    throw new Error("No QR content returned");
                }
                showPhotoReady();
                checkIn(decodedText);
            })
            .catch(function () {
                showPhotoReady();
                showErrorResult("No readable QR code was found. Keep the whole QR visible, avoid glare, and tap the camera checkmark or Use photo before returning.", null);
            });
    }

    manualForm.addEventListener("submit", function (event) {
        event.preventDefault();
        checkIn(tokenInput.value);
    });

    retryCameraBtn.addEventListener("click", loadCameras);
    scanQrPhotoBtn.addEventListener("click", function () {
        qrPhotoInput.click();
    });
    qrPhotoInput.addEventListener("change", function () {
        const file = qrPhotoInput.files && qrPhotoInput.files[0];
        qrPhotoInput.value = "";
        scanQrPhoto(file);
    });
    chooseQrImageBtn.addEventListener("click", function () {
        qrImageInput.click();
    });
    qrImageInput.addEventListener("change", function () {
        const file = qrImageInput.files && qrImageInput.files[0];
        qrImageInput.value = "";
        scanQrPhoto(file);
    });
    cameraSelect.addEventListener("change", function () {
        if (cameraSelect.value) {
            startScanner(cameraSelect.value);
        }
    });
    scanNextBtn.addEventListener("click", prepareNextScan);
    openOverrideBtn.addEventListener("click", openOverrideDialog);
    document.getElementById("closeQrOverrideBtn").addEventListener("click", closeOverrideDialog);
    document.getElementById("cancelQrOverrideBtn").addEventListener("click", closeOverrideDialog);
    overrideDialog.addEventListener("click", function (event) {
        if (event.target === overrideDialog) {
            closeOverrideDialog();
        }
    });
    overrideForm.addEventListener("submit", function (event) {
        event.preventDefault();
        if (!pendingOverride) {
            closeOverrideDialog();
            return;
        }
        const reason = overrideReason.value.trim();
        if (reason.length < 5) {
            overrideError.textContent = "Enter a clear reason with at least 5 characters.";
            overrideError.hidden = false;
            return;
        }
        confirmOverrideBtn.disabled = true;
        confirmOverrideBtn.textContent = "Authorizing…";
        overrideError.hidden = true;
        fetch("create_qr_override.php", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                appointment_id: Number(pendingOverride.appointment.appointment_id),
                reason: reason,
                valid_minutes: Number(overrideMinutes.value || 30),
            }),
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || "Could not authorize the override.");
                }
                const token = pendingOverride.token;
                closeOverrideDialog();
                scanLocked = false;
                lastSentAt = 0;
                checkIn(token);
            })
            .catch(function (error) {
                overrideError.textContent = error.message || "Could not authorize the override.";
                overrideError.hidden = false;
            })
            .finally(function () {
                confirmOverrideBtn.disabled = false;
                confirmOverrideBtn.textContent = "Authorize and check in";
            });
    });

    window.addEventListener("beforeunload", function () {
        window.clearTimeout(nextScanTimer);
        if (scanner && scannerRunning && typeof scanner.stop === "function") {
            scanner.stop().catch(function () {});
        }
    });

    showReadyResult();
    loadCameras();
})();
