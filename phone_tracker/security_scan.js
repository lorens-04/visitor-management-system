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
    const scanNextBtn = document.getElementById("scanNextBtn");

    let scanner = null;
    let scannerRunning = false;
    let scannerPaused = false;
    let scanLocked = false;
    let nextScanTimer = 0;
    let lastSentToken = "";
    let lastSentAt = 0;

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

    function showReadyResult() {
        scanResult.className = "security-scan-result is-ready";
        scanResultLabel.textContent = "Scanner ready";
        scanResultTitle.textContent = "Waiting for a visitor pass";
        scanResultText.textContent = "A successful scan will show the visitor details here.";
        scanResultDetails.hidden = true;
        scanNextBtn.hidden = true;
    }

    function showProcessingResult() {
        scanResult.className = "security-scan-result is-processing";
        scanResultLabel.textContent = "Checking pass";
        scanResultTitle.textContent = "Please wait...";
        scanResultText.textContent = "Verifying the visitor appointment and check-in status.";
        scanResultDetails.hidden = true;
        scanNextBtn.hidden = true;
    }

    function showErrorResult(message) {
        scanResult.className = "security-scan-result is-error";
        scanResultLabel.textContent = "Pass not accepted";
        scanResultTitle.textContent = "Check-in unsuccessful";
        scanResultText.textContent = message || "The visitor pass could not be verified.";
        scanResultDetails.hidden = true;
        scanNextBtn.hidden = true;
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
        scanNextBtn.hidden = false;
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
        tokenInput.value = "";
        showReadyResult();

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
            showErrorResult("That visitor pass code is invalid or incomplete. Ask the visitor to reopen their QR pass and try again.");
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
                    scanLocked = false;
                    submitTokenBtn.disabled = false;
                    showErrorResult((data && data.message) || "The visitor pass could not be checked in.");
                    return;
                }

                pauseScanner();
                showSuccessResult(data);
                nextScanTimer = window.setTimeout(prepareNextScan, 6000);
            })
            .catch(function () {
                scanLocked = false;
                submitTokenBtn.disabled = false;
                showErrorResult("Could not reach the check-in server. Check the connection and try again.");
            });
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

    manualForm.addEventListener("submit", function (event) {
        event.preventDefault();
        checkIn(tokenInput.value);
    });

    retryCameraBtn.addEventListener("click", loadCameras);
    cameraSelect.addEventListener("change", function () {
        if (cameraSelect.value) {
            startScanner(cameraSelect.value);
        }
    });
    scanNextBtn.addEventListener("click", prepareNextScan);

    window.addEventListener("beforeunload", function () {
        window.clearTimeout(nextScanTimer);
        if (scanner && scannerRunning && typeof scanner.stop === "function") {
            scanner.stop().catch(function () {});
        }
    });

    showReadyResult();
    loadCameras();
})();
