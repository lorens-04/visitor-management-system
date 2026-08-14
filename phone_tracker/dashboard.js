(function () {
    if (!PhoneTrackerAuth.requireRole(["security", "admin"])) {
        return;
    }

    const role = PhoneTrackerAuth.getRole();
    if (role === "security") {
        document.getElementById("roleHint").textContent =
            "Security: manage visitor selection and live location only.";
    } else {
        document.getElementById("roleHint").textContent =
            "Admin: full monitoring. User accounts are under Admin dashboard.";
        document.getElementById("adminLink").hidden = false;
    }

    document.getElementById("logoutBtn").addEventListener("click", function () {
        PhoneTrackerAuth.logout().then(function () {
            window.location.href = "login.html";
        });
    });

    let map = L.map("map").setView([0, 0], 2);
    let latestMarker = null;
    let startMarker = null;
    let endMarker = null;
    let routeLine = null;
    let firstLoad = true;
    let pollHandle = null;

    const deviceEl = document.getElementById("device");
    const selectedDateEl = document.getElementById("selectedDate");
    const startCoordsEl = document.getElementById("startCoords");
    const startTimeEl = document.getElementById("startTime");
    const endCoordsEl = document.getElementById("endCoords");
    const endTimeEl = document.getElementById("endTime");
    const visitorSelectEl = document.getElementById("visitorSelect");
    const visitDateEl = document.getElementById("visitDate");
    const applyFiltersBtn = document.getElementById("applyFiltersBtn");
    const activeVisitsList = document.getElementById("activeVisitsList");

    function statusMeta(rawStatus) {
        const s = String(rawStatus || "").toLowerCase();
        if (s === "checked_in") {
            return { label: "Active", cls: "status-active" };
        }
        if (s === "completed") {
            return { label: "Completed", cls: "status-completed" };
        }
        if (s === "cancelled") {
            return { label: "Cancelled", cls: "status-cancelled" };
        }
        return { label: "Not Active", cls: "status-pending" };
    }

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: "&copy; OpenStreetMap contributors",
    }).addTo(map);

    function clearMapLayers() {
        if (routeLine) {
            map.removeLayer(routeLine);
            routeLine = null;
        }
        if (latestMarker) {
            map.removeLayer(latestMarker);
            latestMarker = null;
        }
        if (startMarker) {
            map.removeLayer(startMarker);
            startMarker = null;
        }
        if (endMarker) {
            map.removeLayer(endMarker);
            endMarker = null;
        }
    }

    function getFilterValues() {
        return {
            device: visitorSelectEl.value.trim(),
            date: visitDateEl.value,
        };
    }

    async function loadVisitors() {
        try {
            const response = await fetch("get_visitors.php");
            const result = await response.json();

            if (result.success && Array.isArray(result.data)) {
                const currentValue = visitorSelectEl.value;
                for (const device of result.data) {
                    const option = document.createElement("option");
                    option.value = device;
                    option.textContent = device;
                    visitorSelectEl.appendChild(option);
                }
                visitorSelectEl.value = currentValue;
            }
        } catch (error) {
            console.error(error);
        }
    }

    async function loadVisitorDates() {
        const device = visitorSelectEl.value.trim();
        visitDateEl.innerHTML = '<option value="">Select Date</option>';

        if (!device) {
            return;
        }

        try {
            const response = await fetch(`get_visitor_dates.php?device=${encodeURIComponent(device)}`);
            const result = await response.json();

            if (result.success && Array.isArray(result.data)) {
                for (const date of result.data) {
                    const option = document.createElement("option");
                    option.value = date;
                    option.textContent = date;
                    visitDateEl.appendChild(option);
                }

                if (result.data.length > 0) {
                    visitDateEl.value = result.data[0];
                }
            }
        } catch (error) {
            console.error(error);
        }
    }

    async function loadActiveVisits() {
        if (!activeVisitsList) {
            return;
        }
        try {
            const response = await fetch("security_active_visits.php", {
                credentials: "same-origin",
            });
            const result = await response.json();
            if (!result || !result.success || !Array.isArray(result.data)) {
                activeVisitsList.innerHTML = '<p class="login-error">Could not load active visits.</p>';
                return;
            }
            if (result.data.length === 0) {
                activeVisitsList.innerHTML = '<p class="muted small">No active visits right now.</p>';
                return;
            }

            activeVisitsList.innerHTML = "";
            result.data.forEach(function (visit) {
                const row = document.createElement("div");
                row.className = "appointment-item";

                const left = document.createElement("div");
                left.className = "appointment-open";
                left.style.cursor = "default";
                const st = statusMeta("checked_in");
                left.innerHTML =
                    "<strong>" + (visit.visitor_full_name || "Visitor") + "</strong><br>" +
                    '<span class="muted small">' + (visit.office_label || visit.office_code || "-") + " | Device: " + (visit.device_name || "-") + " </span>" +
                    '<span class="status-badge ' + st.cls + '">' + st.label + "</span>";

                const endBtn = document.createElement("button");
                endBtn.type = "button";
                endBtn.className = "button-danger button-small";
                endBtn.textContent = "End Visit";
                endBtn.addEventListener("click", function () {
                    endBtn.disabled = true;
                    fetch("complete_appointment.php", {
                        method: "POST",
                        credentials: "same-origin",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ id: visit.id }),
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (!data || !data.success) {
                                endBtn.disabled = false;
                                return;
                            }
                            loadActiveVisits();
                        })
                        .catch(function () {
                            endBtn.disabled = false;
                        });
                });

                row.appendChild(left);
                row.appendChild(endBtn);
                activeVisitsList.appendChild(row);
            });
        } catch (error) {
            activeVisitsList.innerHTML = '<p class="login-error">Could not reach the server.</p>';
        }
    }

    async function loadRouteHistory() {
        try {
            const filters = getFilterValues();
            if (!filters.device) {
                clearMapLayers();
                deviceEl.textContent = "Select a visitor";
                selectedDateEl.textContent = "-";
                startCoordsEl.textContent = "-";
                startTimeEl.textContent = "-";
                endCoordsEl.textContent = "-";
                endTimeEl.textContent = "-";
                return;
            }

            const params = new URLSearchParams();
            params.set("device", filters.device);
            if (filters.date) {
                params.set("date", filters.date);
            } else {
                clearMapLayers();
                deviceEl.textContent = "Select a date";
                selectedDateEl.textContent = "-";
                startCoordsEl.textContent = "-";
                startTimeEl.textContent = "-";
                endCoordsEl.textContent = "-";
                endTimeEl.textContent = "-";
                return;
            }

            const response = await fetch(`get_route_history.php?${params.toString()}`);
            const result = await response.json();

            if (!result.success || !result.data || !result.data.start_point || !result.data.end_point) {
                clearMapLayers();
                deviceEl.textContent = "No data yet";
                selectedDateEl.textContent = filters.date || "-";
                startCoordsEl.textContent = "-";
                startTimeEl.textContent = "-";
                endCoordsEl.textContent = "-";
                endTimeEl.textContent = "-";
                return;
            }

            const startPoint = result.data.start_point;
            const endPoint = result.data.end_point;
            const startLatLng = [parseFloat(startPoint.latitude), parseFloat(startPoint.longitude)];
            const endLatLng = [parseFloat(endPoint.latitude), parseFloat(endPoint.longitude)];

            if (!routeLine) {
                routeLine = L.polyline([startLatLng, endLatLng], { color: "#2563eb", weight: 3, opacity: 0.85 }).addTo(map);
            } else {
                routeLine.setLatLngs([startLatLng, endLatLng]);
            }

            if (!latestMarker) {
                latestMarker = L.marker(endLatLng).addTo(map);
            } else {
                latestMarker.setLatLng(endLatLng);
            }

            if (!startMarker) {
                startMarker = L.circleMarker(startLatLng, {
                    radius: 6,
                    color: "#16a34a",
                    fillColor: "#16a34a",
                    fillOpacity: 1,
                }).addTo(map);
            } else {
                startMarker.setLatLng(startLatLng);
            }

            if (!endMarker) {
                endMarker = L.circleMarker(endLatLng, {
                    radius: 6,
                    color: "#dc2626",
                    fillColor: "#dc2626",
                    fillOpacity: 1,
                }).addTo(map);
            } else {
                endMarker.setLatLng(endLatLng);
            }

            deviceEl.textContent = result.visitor;
            selectedDateEl.textContent = result.date;
            startCoordsEl.textContent = `${startPoint.latitude}, ${startPoint.longitude}`;
            startTimeEl.textContent = startPoint.recorded_at;
            endCoordsEl.textContent = `${endPoint.latitude}, ${endPoint.longitude}`;
            endTimeEl.textContent = endPoint.recorded_at;

            latestMarker.bindPopup(
                `<strong>${endPoint.device_name}</strong><br>` +
                    `End Lat: ${endPoint.latitude}<br>` +
                    `End Lng: ${endPoint.longitude}<br>` +
                    `Recorded: ${endPoint.recorded_at}`
            );
            startMarker.bindPopup(`Start: ${startPoint.recorded_at}`);
            endMarker.bindPopup(`End: ${endPoint.recorded_at}`);

            if (firstLoad) {
                map.fitBounds(routeLine.getBounds(), { padding: [20, 20] });
                firstLoad = false;
            }
        } catch (error) {
            console.error(error);
        }
    }

    applyFiltersBtn.addEventListener("click", () => {
        firstLoad = true;
        loadRouteHistory();
    });

    visitorSelectEl.addEventListener("change", () => {
        firstLoad = true;
        loadVisitorDates().then(() => {
            loadRouteHistory();
        });
    });

    visitDateEl.addEventListener("change", () => {
        firstLoad = true;
        loadRouteHistory();
    });

    async function initDashboard() {
        await loadVisitors();
        await loadVisitorDates();
        await loadActiveVisits();
        pollHandle = setInterval(loadRouteHistory, 5000);
        setInterval(loadActiveVisits, 5000);
    }

    initDashboard();
})();
