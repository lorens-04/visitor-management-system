(function () {
    "use strict";

    if (typeof PhoneTrackerAuth === "undefined" || !PhoneTrackerAuth.requireRole(["offices"])) {
        return;
    }

    const dayNames = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
    const state = {
        summary: {},
        appointments: [],
        notifications: [],
        unread: 0,
        office: null,
        profile: null,
        profileImageVersion: 0,
        profilePreviewUrl: "",
        selectedAppointmentId: 0,
        recentQuery: "",
        recentStatus: "",
        recentPage: 1,
        recentPageSize: 10,
        requestQuery: "",
        availabilityLoaded: false,
        toastTimer: null,
    };

    function byId(id) {
        return document.getElementById(id);
    }

    function setText(id, value) {
        const element = byId(id);
        if (element) {
            element.textContent = value == null ? "" : String(value);
        }
    }

    function showError(id, message) {
        const element = byId(id);
        if (!element) {
            return;
        }
        element.textContent = message || "";
        element.hidden = !message;
    }

    function profileImageSource(url, bustCache) {
        if (!url) {
            return "assets/icons/profile_icon.svg";
        }
        if (!bustCache) {
            return url;
        }
        return url + (url.indexOf("?") === -1 ? "?" : "&") + "v=" + String(bustCache);
    }

    function setProfileImage(id, url, bustCache) {
        const image = byId(id);
        if (!image) {
            return;
        }
        const fallback = "assets/icons/profile_icon.svg";
        image.onerror = function () {
            image.onerror = null;
            image.src = fallback;
        };
        image.src = profileImageSource(url, bustCache);
    }

    function renderProfile() {
        const profile = state.profile || {};
        const displayName = profile.display_name || PhoneTrackerAuth.getDisplayName() || PhoneTrackerAuth.getUsername() || "Office Personnel";
        const username = profile.username || PhoneTrackerAuth.getUsername() || "office";
        setText("officeProfileName", displayName);
        setText("officeProfileUsername", username);
        setProfileImage("officeProfileAvatar", profile.profile_image_url || "", state.profileImageVersion);
    }

    function clearProfilePreviewUrl() {
        if (state.profilePreviewUrl) {
            URL.revokeObjectURL(state.profilePreviewUrl);
            state.profilePreviewUrl = "";
        }
    }

    function openProfileEditor() {
        clearProfilePreviewUrl();
        const profile = state.profile || {};
        byId("officeProfileDisplayName").value = profile.display_name || PhoneTrackerAuth.getDisplayName() || "";
        byId("officeProfileUsernameReadonly").value = profile.username || PhoneTrackerAuth.getUsername() || "";
        byId("officeProfileImage").value = "";
        byId("officeRemoveProfileImage").checked = false;
        byId("officeRemoveProfileRow").hidden = !profile.profile_image_url;
        setProfileImage("officeProfilePreview", profile.profile_image_url || "", state.profileImageVersion);
        showError("officeProfileError", "");
        byId("officeProfileMenu").hidden = true;
        byId("officeProfileBtn").setAttribute("aria-expanded", "false");
        byId("officeProfileDialog").showModal();
    }

    function closeProfileEditor() {
        clearProfilePreviewUrl();
        byId("officeProfileDialog").close();
    }

    async function fetchJson(url, options) {
        const response = await fetch(url, Object.assign({
            credentials: "same-origin",
            cache: "no-store",
        }, options || {}));
        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            data = { success: false, message: "The server returned an unreadable response." };
        }
        if (response.status === 401 || (data && data.message === "Not authenticated")) {
            window.location.href = "login.html";
            throw new Error("Not authenticated");
        }
        return data;
    }

    function postJson(url, payload) {
        return fetchJson(url, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        });
    }

    function toast(message, stateName) {
        const element = byId("officeToast");
        element.textContent = message;
        element.dataset.state = stateName || "success";
        element.hidden = false;
        if (state.toastTimer) {
            window.clearTimeout(state.toastTimer);
        }
        state.toastTimer = window.setTimeout(function () {
            element.hidden = true;
        }, 4200);
    }

    function parseServerDate(value) {
        if (!value) {
            return null;
        }
        const date = new Date(String(value).replace(" ", "T"));
        return isNaN(date.getTime()) ? null : date;
    }

    function formatDateTime(value, includeYear) {
        const date = parseServerDate(value);
        if (!date) {
            return "—";
        }
        return new Intl.DateTimeFormat("en-PH", {
            month: "short",
            day: "numeric",
            year: includeYear === false ? undefined : "numeric",
            hour: "numeric",
            minute: "2-digit",
        }).format(date);
    }

    function formatSchedule(row) {
        const start = parseServerDate(row.scheduled_start_at || row.appointment_at);
        const end = parseServerDate(row.scheduled_end_at);
        if (!start) {
            return { date: "Schedule unavailable", time: "" };
        }
        const date = new Intl.DateTimeFormat("en-PH", { month: "short", day: "numeric", year: "numeric" }).format(start);
        const startTime = new Intl.DateTimeFormat("en-PH", { hour: "numeric", minute: "2-digit" }).format(start);
        const endTime = end ? new Intl.DateTimeFormat("en-PH", { hour: "numeric", minute: "2-digit" }).format(end) : "";
        return { date: date, time: endTime ? startTime + "–" + endTime : startTime };
    }

    function statusMeta(status) {
        const map = {
            pending_approval: { label: "Pending approval", className: "is-pending" },
            reschedule_proposed: { label: "Waiting for visitor", className: "is-pending" },
            approved: { label: "Approved", className: "is-active" },
            rejected: { label: "Declined", className: "is-cancelled" },
            checked_in: { label: "Checked in", className: "is-active" },
            completed: { label: "Completed", className: "is-completed" },
            cancelled: { label: "Cancelled", className: "is-cancelled" },
            unanswered: { label: "No office response", className: "is-cancelled" },
            window_closed: { label: "Appointment done", className: "is-completed" },
        };
        return map[status] || { label: status || "Unknown", className: "is-completed" };
    }

    function purposeLabel(row) {
        return row.purpose || row.subject || "Purpose not provided";
    }

    function matchesQuery(row, query) {
        if (!query) {
            return true;
        }
        const haystack = [
            row.visitor_full_name,
            row.visitor_email,
            row.registration_code,
            row.purpose,
            row.subject,
            row.destination,
            row.contact_number,
        ].join(" ").toLowerCase();
        return haystack.indexOf(query.toLowerCase()) !== -1;
    }

    function appendTextCell(row, primary, secondary, className) {
        const cell = document.createElement("td");
        if (className) {
            cell.className = className;
        }
        const strong = document.createElement("strong");
        strong.className = "office-cell-primary";
        strong.textContent = primary || "—";
        cell.appendChild(strong);
        if (secondary) {
            const small = document.createElement("small");
            small.className = "office-cell-secondary";
            small.textContent = secondary;
            cell.appendChild(small);
        }
        row.appendChild(cell);
        return cell;
    }

    function appendStatusCell(row, status) {
        const cell = document.createElement("td");
        const meta = statusMeta(status);
        const badge = document.createElement("span");
        badge.className = "admin-status " + meta.className;
        badge.textContent = meta.label;
        cell.appendChild(badge);
        row.appendChild(cell);
    }

    function appendViewButton(row, appointmentId) {
        const cell = document.createElement("td");
        cell.className = "admin-row-actions";
        const button = document.createElement("button");
        button.type = "button";
        button.className = "office-view-button";
        button.dataset.appointmentId = String(appointmentId);
        button.textContent = "View";
        cell.appendChild(button);
        row.appendChild(cell);
    }

    function makeAppointmentRow(item, kind) {
        const row = document.createElement("tr");
        const schedule = formatSchedule(item);
        appendTextCell(row, item.visitor_full_name, item.registration_code || item.visitor_email || "");
        appendTextCell(row, purposeLabel(item), item.subject && item.subject !== item.purpose ? item.subject : (item.destination || ""));
        appendTextCell(row, schedule.date, schedule.time);
        if (kind === "requests") {
            appendTextCell(row, formatDateTime(item.created_at, false), "");
        }
        appendStatusCell(row, item.status);
        if (kind === "history") {
            appendTextCell(row, item.processed_by || (item.status === "unanswered" || item.status === "window_closed" ? "System" : "—"), "");
            appendTextCell(row, formatDateTime(item.processed_at || item.status_updated_at, false), "");
        }
        appendViewButton(row, item.id);
        return row;
    }

    function renderRecent() {
        const body = byId("officeRecentBody");
        body.replaceChildren();
        const todayDate = new Date();
        const today = todayDate.getFullYear() + "-" + String(todayDate.getMonth() + 1).padStart(2, "0") + "-" + String(todayDate.getDate()).padStart(2, "0");
        const rows = state.appointments
            .filter(function (item) {
                const statusMatch = !state.recentStatus ||
                    (state.recentStatus === "today" ? String(item.scheduled_start_at || "").slice(0, 10) === today : item.status === state.recentStatus);
                return statusMatch && matchesQuery(item, state.recentQuery);
            })
            .sort(function (left, right) {
                return String(right.scheduled_start_at || right.created_at || "").localeCompare(String(left.scheduled_start_at || left.created_at || ""));
            });
        const totalPages = Math.max(1, Math.ceil(rows.length / state.recentPageSize));
        state.recentPage = Math.min(state.recentPage, totalPages);
        const start = (state.recentPage - 1) * state.recentPageSize;
        rows.slice(start, start + state.recentPageSize).forEach(function (item) {
            body.appendChild(makeAppointmentRow(item, "recent"));
        });
        byId("officeRecentEmpty").hidden = rows.length !== 0;
        body.parentElement.hidden = rows.length === 0;
        renderRecentPagination(rows.length, totalPages);
    }

    function renderRequests() {
        const body = byId("officeRequestsBody");
        body.replaceChildren();
        const rows = state.appointments
            .filter(function (item) { return item.status === "pending_approval" && matchesQuery(item, state.requestQuery); })
            .sort(function (left, right) { return String(left.created_at).localeCompare(String(right.created_at)); });
        rows.forEach(function (item) { body.appendChild(makeAppointmentRow(item, "requests")); });
        byId("officeRequestsEmpty").hidden = rows.length !== 0;
        body.parentElement.hidden = rows.length === 0;
        setText("officeRequestsCount", rows.length);
    }

    function renderRecentPagination(totalRows, totalPages) {
        const wrap = byId("officeAppointmentsPagination");
        wrap.replaceChildren();
        if (totalRows === 0 || totalPages <= 1) {
            return;
        }
        const summary = document.createElement("p");
        const start = (state.recentPage - 1) * state.recentPageSize + 1;
        const end = Math.min(totalRows, state.recentPage * state.recentPageSize);
        summary.textContent = "Showing " + start + "–" + end + " of " + totalRows;
        const controls = document.createElement("div");
        controls.className = "admin-page-buttons";
        for (let page = 1; page <= totalPages; page += 1) {
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = String(page);
            button.classList.toggle("is-active", page === state.recentPage);
            button.addEventListener("click", function () {
                state.recentPage = page;
                renderRecent();
            });
            controls.appendChild(button);
        }
        wrap.append(summary, controls);
    }

    function renderNotifications() {
        const count = byId("officeNotificationCount");
        count.textContent = state.unread > 99 ? "99+" : String(state.unread);
        count.hidden = state.unread === 0;
        setText("officeNotificationSummary", state.unread ? state.unread + " unread update" + (state.unread === 1 ? "" : "s") : "No unread updates");
        const list = byId("officeNotificationList");
        list.replaceChildren();
        state.notifications.forEach(function (notification) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "office-notification-item" + (notification.read_at ? "" : " is-unread");
            button.dataset.notificationId = String(notification.id);
            if (notification.appointment_id) {
                button.dataset.appointmentId = String(notification.appointment_id);
            }
            const marker = document.createElement("span");
            marker.className = "admin-notification-marker";
            const copy = document.createElement("span");
            const title = document.createElement("strong");
            title.textContent = notification.title || "Appointment update";
            const message = document.createElement("span");
            message.textContent = notification.message || "";
            const time = document.createElement("time");
            time.textContent = formatDateTime(notification.created_at, false);
            copy.append(title, message, time);
            button.append(marker, copy);
            list.appendChild(button);
        });
        byId("officeNotificationEmpty").hidden = state.notifications.length !== 0;
        byId("markOfficeNotificationsReadBtn").hidden = state.unread === 0;
    }

    function renderDashboardData() {
        setText("officePendingCount", state.summary.pending_approvals || 0);
        setText("officeApprovedCount", state.summary.approved || 0);
        setText("officeRejectedCount", state.summary.rejected || 0);
        setText("officeTodayCount", state.summary.todays_appointments || 0);
        const navCount = byId("officeRequestNavCount");
        navCount.textContent = String(state.summary.pending_approvals || 0);
        navCount.hidden = Number(state.summary.pending_approvals || 0) === 0;
        setText("officeProfileOffice", state.office ? state.office.label : "Assigned office");
        const officeScope = state.office ? state.office.label : "your assigned office";
        setText("officeDashboardScope", "Showing " + officeScope + " records only.");
        setText("officeRequestScope", "Only requests addressed to " + officeScope + " appear here.");
        renderProfile();
        setText("officeDashboardDate", "Search and filter every appointment for " + officeScope + ".");
        renderRecent();
        renderRequests();
        renderNotifications();
    }

    async function loadOfficeData(silent) {
        if (!silent) {
            showError("officePageError", "");
        }
        try {
            const results = await Promise.all([
                fetchJson("office_dashboard.php"),
                fetchJson("list_appointments.php"),
            ]);
            const dashboard = results[0];
            const appointments = results[1];
            if (!dashboard || !dashboard.success) {
                throw new Error((dashboard && dashboard.message) || "Could not load the Office dashboard.");
            }
            if (!appointments || !appointments.success) {
                throw new Error((appointments && appointments.message) || "Could not load appointments.");
            }
            state.summary = dashboard.summary || {};
            state.notifications = Array.isArray(dashboard.notifications) ? dashboard.notifications : [];
            state.unread = Number(dashboard.unread_notifications || 0);
            state.office = dashboard.office || null;
            state.profile = dashboard.profile || state.profile;
            state.appointments = Array.isArray(appointments.data) ? appointments.data : [];
            renderDashboardData();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showError("officePageError", error.message || "Could not reach the server.");
            }
        }
    }

    function switchView(view) {
        if (view === "history") {
            view = "dashboard";
        }
        const valid = ["dashboard", "requests", "availability"];
        if (valid.indexOf(view) === -1) {
            view = "dashboard";
        }
        document.querySelectorAll("[data-office-panel]").forEach(function (panel) {
            panel.hidden = panel.dataset.officePanel !== view;
        });
        document.querySelectorAll("[data-office-view]").forEach(function (button) {
            const active = button.dataset.officeView === view;
            button.classList.toggle("is-active", active);
            if (active) {
                button.setAttribute("aria-current", "page");
            } else {
                button.removeAttribute("aria-current");
            }
        });
        const labels = { dashboard: "Appointments", requests: "Appointment Requests", availability: "Availability" };
        setText("officeMobileTitle", labels[view]);
        window.history.replaceState(null, "", window.location.pathname + "#" + view);
        window.scrollTo({ top: 0, behavior: "smooth" });
        if (view === "availability" && !state.availabilityLoaded) {
            loadAvailability();
        }
    }

    function detailPair(label, value, full) {
        const group = document.createElement("div");
        if (full) {
            group.className = "office-detail-full";
        }
        const term = document.createElement("dt");
        term.textContent = label;
        const definition = document.createElement("dd");
        definition.textContent = value || "Not provided";
        group.append(term, definition);
        return group;
    }

    function renderProposal(proposal) {
        const wrap = byId("officeProposalSummary");
        wrap.replaceChildren();
        if (!proposal) {
            wrap.hidden = true;
            return;
        }
        const heading = document.createElement("strong");
        heading.textContent = "Proposed schedule — " + proposal.status;
        const reason = document.createElement("p");
        reason.textContent = proposal.reason || "No reason provided";
        const list = document.createElement("ul");
        (proposal.slots || []).forEach(function (slot) {
            const item = document.createElement("li");
            item.textContent = formatDateTime(slot.scheduled_start_at) + (Number(slot.is_selected) ? " — selected" : "");
            list.appendChild(item);
        });
        wrap.append(heading, reason, list);
        wrap.hidden = false;
    }

    function renderAppointmentHistory(history) {
        const wrap = byId("officeAppointmentHistory");
        wrap.replaceChildren();
        if (!history || history.length === 0) {
            const empty = document.createElement("p");
            empty.textContent = "No recorded activity.";
            wrap.appendChild(empty);
            return;
        }
        history.forEach(function (event) {
            const item = document.createElement("article");
            const meta = statusMeta(event.to_status);
            const marker = document.createElement("span");
            marker.className = "office-timeline-marker " + meta.className;
            const copy = document.createElement("div");
            const title = document.createElement("strong");
            title.textContent = meta.label;
            const note = document.createElement("p");
            note.textContent = event.note || "Status updated";
            const time = document.createElement("small");
            time.textContent = formatDateTime(event.changed_at) + " · " + (event.changed_by || "System");
            copy.append(title, note, time);
            item.append(marker, copy);
            wrap.appendChild(item);
        });
    }

    async function openAppointment(appointmentId) {
        state.selectedAppointmentId = Number(appointmentId);
        showError("officeAppointmentError", "");
        const data = await fetchJson("office_appointment.php?id=" + encodeURIComponent(appointmentId));
        if (!data || !data.success) {
            toast((data && data.message) || "Could not open appointment.", "error");
            return;
        }
        const appointment = data.appointment;
        setText("officeAppointmentTitle", appointment.visitor_full_name || "Visitor request");
        setText("officeAppointmentCode", appointment.registration_code || "No registration code");
        const meta = statusMeta(appointment.status);
        const status = byId("officeAppointmentStatus");
        status.className = "admin-status " + meta.className;
        status.textContent = meta.label;
        const schedule = formatSchedule(appointment);
        const details = byId("officeAppointmentDetails");
        details.replaceChildren(
            detailPair("Visitor's name", appointment.visitor_full_name),
            detailPair("Contact number", appointment.contact_number),
            detailPair("Email", appointment.visitor_email),
            detailPair("Visit type", appointment.visit_type === "walk_in" ? "Walk-in" : "Appointment"),
            detailPair("Purpose", appointment.purpose),
            detailPair("Destination", appointment.destination),
            detailPair("Subject / concern", appointment.subject),
            detailPair("Schedule", schedule.date + " · " + schedule.time),
            detailPair("Additional details", appointment.additional_details, true),
            detailPair("Date submitted", formatDateTime(appointment.created_at), true)
        );
        if (appointment.rejection_reason) {
            details.appendChild(detailPair("Decision reason", appointment.rejection_reason, true));
        }
        renderProposal(data.reschedule_proposal);
        renderAppointmentHistory(data.history || []);
        byId("officeAppointmentActions").hidden = appointment.status !== "pending_approval";
        byId("officeAppointmentDialog").showModal();
    }

    async function processAppointment(action, reason) {
        showError("officeAppointmentError", "");
        const approveButton = byId("approveOfficeAppointmentBtn");
        approveButton.disabled = true;
        try {
            const data = await postJson("office_appointment_action.php", {
                appointment_id: state.selectedAppointmentId,
                action: action,
                reason: reason || "",
            });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not update the appointment.");
            }
            [byId("officeDeclineDialog"), byId("officeAppointmentDialog")].forEach(function (dialog) {
                if (dialog.open) { dialog.close(); }
            });
            toast(data.message || "Appointment updated.");
            await loadOfficeData(true);
        } catch (error) {
            const target = action === "reject" && byId("officeDeclineDialog").open ? "officeDeclineError" : "officeAppointmentError";
            showError(target, error.message || "Could not reach the server.");
        } finally {
            approveButton.disabled = false;
            byId("confirmOfficeDeclineBtn").disabled = false;
        }
    }

    function toInputDate(date) {
        const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        return local.toISOString().slice(0, 16);
    }

    function setFutureMinimums() {
        const now = new Date();
        const minimum = toInputDate(now);
        document.querySelectorAll(".office-proposed-slot, #officeExceptionStart, #officeExceptionEnd").forEach(function (input) {
            input.min = minimum;
        });
    }

    function defaultScheduleRules() {
        return dayNames.map(function (_, index) {
            return { day_of_week: index + 1, enabled: index < 5, start_time: "08:00", end_time: "17:00" };
        });
    }

    function renderWeeklySchedule(rules) {
        const wrap = byId("officeWeeklySchedule");
        wrap.replaceChildren();
        const normalized = defaultScheduleRules();
        if (rules && rules.length) {
            normalized.forEach(function (day) { day.enabled = false; });
            rules.forEach(function (rule) {
                const day = normalized[Number(rule.day_of_week) - 1];
                if (day && Number(rule.is_active) === 1) {
                    day.enabled = true;
                    day.start_time = String(rule.start_time || "08:00").slice(0, 5);
                    day.end_time = String(rule.end_time || "17:00").slice(0, 5);
                }
            });
        }
        normalized.forEach(function (day) {
            const row = document.createElement("div");
            row.className = "office-schedule-row";
            row.dataset.day = String(day.day_of_week);
            const toggleLabel = document.createElement("label");
            toggleLabel.className = "office-day-toggle";
            const checkbox = document.createElement("input");
            checkbox.type = "checkbox";
            checkbox.className = "office-day-enabled";
            checkbox.checked = day.enabled;
            const dayName = document.createElement("strong");
            dayName.textContent = dayNames[day.day_of_week - 1];
            toggleLabel.append(checkbox, dayName);
            const start = document.createElement("input");
            start.type = "time";
            start.className = "office-day-start";
            start.value = day.start_time;
            start.disabled = !day.enabled;
            const separator = document.createElement("span");
            separator.textContent = "to";
            const end = document.createElement("input");
            end.type = "time";
            end.className = "office-day-end";
            end.value = day.end_time;
            end.disabled = !day.enabled;
            const stateText = document.createElement("span");
            stateText.className = "office-day-state";
            stateText.textContent = day.enabled ? "Open" : "Closed";
            checkbox.addEventListener("change", function () {
                start.disabled = !checkbox.checked;
                end.disabled = !checkbox.checked;
                stateText.textContent = checkbox.checked ? "Open" : "Closed";
                row.classList.toggle("is-closed", !checkbox.checked);
            });
            row.classList.toggle("is-closed", !day.enabled);
            row.append(toggleLabel, start, separator, end, stateText);
            wrap.appendChild(row);
        });
    }

    function renderAvailability(data) {
        const settings = data.settings || {};
        const accepting = Number(settings.accepting_visitors) === 1;
        byId("officeAcceptingVisitors").checked = accepting;
        byId("officeClosureFields").hidden = accepting;
        byId("officeUnavailableReason").value = settings.unavailable_reason || "";
        byId("officeAvailableAgain").value = settings.available_again_at ? String(settings.available_again_at).replace(" ", "T").slice(0, 16) : "";
        byId("officeSlotDuration").value = String(settings.slot_duration_minutes || 30);
        byId("officeSlotCapacity").value = String(settings.maximum_visitors_per_slot || 1);
        const chip = byId("officeAvailabilityChip");
        chip.textContent = accepting ? "Accepting visitors" : "Not accepting visitors";
        chip.classList.toggle("is-closed", !accepting);
        renderWeeklySchedule(data.rules || []);
        renderExceptions(data.exceptions || []);
    }

    function renderExceptions(exceptions) {
        const list = byId("officeExceptionList");
        list.replaceChildren();
        exceptions.forEach(function (exception) {
            const item = document.createElement("article");
            item.className = "office-exception-item";
            const marker = document.createElement("span");
            marker.className = Number(exception.is_available) === 1 ? "is-available" : "is-closed";
            const copy = document.createElement("div");
            const title = document.createElement("strong");
            title.textContent = Number(exception.is_available) === 1 ? "Extra visitor hours" : "Office unavailable";
            const schedule = document.createElement("span");
            schedule.textContent = formatDateTime(exception.starts_at) + " – " + formatDateTime(exception.ends_at);
            const reason = document.createElement("small");
            reason.textContent = exception.reason || "No reason provided";
            copy.append(title, schedule, reason);
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "office-remove-exception";
            remove.dataset.exceptionId = String(exception.id);
            remove.textContent = "Remove";
            item.append(marker, copy, remove);
            list.appendChild(item);
        });
        byId("officeExceptionEmpty").hidden = exceptions.length !== 0;
    }

    async function loadAvailability() {
        showError("officeAvailabilityError", "");
        try {
            const data = await fetchJson("office_availability.php");
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not load availability.");
            }
            renderAvailability(data);
            state.availabilityLoaded = true;
        } catch (error) {
            showError("officeAvailabilityError", error.message || "Could not reach the server.");
        }
    }

    async function saveAvailability(event) {
        event.preventDefault();
        showError("officeAvailabilityError", "");
        const schedules = Array.from(document.querySelectorAll(".office-schedule-row")).map(function (row) {
            return {
                day_of_week: Number(row.dataset.day),
                enabled: row.querySelector(".office-day-enabled").checked,
                start_time: row.querySelector(".office-day-start").value,
                end_time: row.querySelector(".office-day-end").value,
            };
        });
        const button = byId("saveOfficeAvailabilityBtn");
        button.disabled = true;
        try {
            const data = await postJson("office_availability.php", {
                action: "save_schedule",
                accepting_visitors: byId("officeAcceptingVisitors").checked,
                slot_duration_minutes: Number(byId("officeSlotDuration").value),
                maximum_visitors_per_slot: Number(byId("officeSlotCapacity").value),
                unavailable_reason: byId("officeUnavailableReason").value.trim(),
                available_again_at: byId("officeAvailableAgain").value,
                schedules: schedules,
            });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not save availability.");
            }
            renderAvailability(data);
            toast(data.message || "Availability saved.");
        } catch (error) {
            showError("officeAvailabilityError", error.message || "Could not reach the server.");
        } finally {
            button.disabled = false;
        }
    }

    document.querySelectorAll("[data-office-view]").forEach(function (button) {
        button.addEventListener("click", function () { switchView(button.dataset.officeView); });
    });
    document.querySelectorAll("[data-summary-view]").forEach(function (button) {
        button.addEventListener("click", function () {
            const view = button.dataset.summaryView;
            const status = button.dataset.summaryStatus || "";
            if (view === "dashboard") {
                state.recentStatus = status;
                byId("officeRecentStatus").value = status;
                state.recentPage = 1;
                renderRecent();
            }
            switchView(view);
        });
    });

    byId("officeRecentSearch").addEventListener("input", function (event) { state.recentQuery = event.target.value.trim(); state.recentPage = 1; renderRecent(); });
    byId("officeRecentStatus").addEventListener("change", function (event) { state.recentStatus = event.target.value; state.recentPage = 1; renderRecent(); });
    byId("officeRequestSearch").addEventListener("input", function (event) { state.requestQuery = event.target.value.trim(); renderRequests(); });

    document.addEventListener("click", function (event) {
        const appointmentButton = event.target.closest(".office-view-button[data-appointment-id]");
        if (appointmentButton) {
            openAppointment(Number(appointmentButton.dataset.appointmentId));
            return;
        }
        if (!byId("officeProfileMenu").hidden && !byId("officeProfileMenu").contains(event.target) && !byId("officeProfileBtn").contains(event.target)) {
            byId("officeProfileMenu").hidden = true;
            byId("officeProfileBtn").setAttribute("aria-expanded", "false");
        }
        if (!byId("officeNotificationPanel").hidden && !byId("officeNotificationPanel").contains(event.target) && !byId("officeNotificationBtn").contains(event.target)) {
            byId("officeNotificationPanel").hidden = true;
            byId("officeNotificationBtn").setAttribute("aria-expanded", "false");
        }
    });

    byId("closeOfficeAppointmentBtn").addEventListener("click", function () { byId("officeAppointmentDialog").close(); });
    byId("approveOfficeAppointmentBtn").addEventListener("click", function () {
        if (window.confirm("Approve this appointment and issue the visitor's QR pass?")) {
            processAppointment("approve", "");
        }
    });
    byId("openDeclineAppointmentBtn").addEventListener("click", function () {
        byId("officeDeclineReason").value = "";
        showError("officeDeclineError", "");
        byId("officeDeclineDialog").showModal();
    });
    byId("closeOfficeDeclineBtn").addEventListener("click", function () { byId("officeDeclineDialog").close(); });
    byId("cancelOfficeDeclineBtn").addEventListener("click", function () { byId("officeDeclineDialog").close(); });
    byId("officeDeclineForm").addEventListener("submit", function (event) {
        event.preventDefault();
        const reason = byId("officeDeclineReason").value.trim();
        if (reason.length < 5) {
            showError("officeDeclineError", "Please give the visitor a clear reason.");
            return;
        }
        byId("confirmOfficeDeclineBtn").disabled = true;
        processAppointment("reject", reason);
    });

    byId("openRescheduleAppointmentBtn").addEventListener("click", function () {
        byId("officeRescheduleForm").reset();
        setFutureMinimums();
        showError("officeRescheduleError", "");
        byId("officeRescheduleDialog").showModal();
    });
    byId("closeOfficeRescheduleBtn").addEventListener("click", function () { byId("officeRescheduleDialog").close(); });
    byId("cancelOfficeRescheduleBtn").addEventListener("click", function () { byId("officeRescheduleDialog").close(); });
    byId("officeRescheduleForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        showError("officeRescheduleError", "");
        const slots = Array.from(document.querySelectorAll(".office-proposed-slot")).map(function (input) { return input.value; }).filter(Boolean);
        const button = byId("confirmOfficeRescheduleBtn");
        button.disabled = true;
        try {
            const data = await postJson("office_propose_reschedule.php", {
                appointment_id: state.selectedAppointmentId,
                reason: byId("officeRescheduleReason").value.trim(),
                message: byId("officeRescheduleMessage").value.trim(),
                slots: slots,
            });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not send the proposed schedules.");
            }
            byId("officeRescheduleDialog").close();
            byId("officeAppointmentDialog").close();
            toast(data.message || "Schedule options sent.");
            await loadOfficeData(true);
        } catch (error) {
            showError("officeRescheduleError", error.message || "Could not reach the server.");
        } finally {
            button.disabled = false;
        }
    });

    byId("officeAcceptingVisitors").addEventListener("change", function (event) {
        byId("officeClosureFields").hidden = event.target.checked;
    });
    byId("officeAvailabilityForm").addEventListener("submit", saveAvailability);
    byId("addOfficeExceptionBtn").addEventListener("click", function () {
        byId("officeExceptionForm").reset();
        setFutureMinimums();
        const start = new Date(Date.now() + 60 * 60 * 1000);
        start.setMinutes(0, 0, 0);
        const end = new Date(start.getTime() + 60 * 60 * 1000);
        byId("officeExceptionStart").value = toInputDate(start);
        byId("officeExceptionEnd").value = toInputDate(end);
        byId("officeExceptionCapacityField").hidden = true;
        showError("officeExceptionError", "");
        byId("officeExceptionDialog").showModal();
    });
    byId("officeExceptionType").addEventListener("change", function (event) {
        byId("officeExceptionCapacityField").hidden = event.target.value !== "available";
    });
    byId("closeOfficeExceptionBtn").addEventListener("click", function () { byId("officeExceptionDialog").close(); });
    byId("cancelOfficeExceptionBtn").addEventListener("click", function () { byId("officeExceptionDialog").close(); });
    byId("officeExceptionForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        showError("officeExceptionError", "");
        const button = byId("saveOfficeExceptionBtn");
        button.disabled = true;
        try {
            const type = byId("officeExceptionType").value;
            const data = await postJson("office_availability.php", {
                action: "add_exception",
                starts_at: byId("officeExceptionStart").value,
                ends_at: byId("officeExceptionEnd").value,
                is_available: type === "available",
                capacity_override: type === "available" ? byId("officeExceptionCapacity").value : null,
                reason: byId("officeExceptionReason").value.trim(),
            });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not add the schedule change.");
            }
            renderAvailability(data);
            byId("officeExceptionDialog").close();
            toast(data.message || "Schedule change added.");
        } catch (error) {
            showError("officeExceptionError", error.message || "Could not reach the server.");
        } finally {
            button.disabled = false;
        }
    });
    byId("officeExceptionList").addEventListener("click", async function (event) {
        const button = event.target.closest("button[data-exception-id]");
        if (!button || !window.confirm("Remove this temporary schedule change?")) {
            return;
        }
        button.disabled = true;
        const data = await postJson("office_availability.php", { action: "delete_exception", exception_id: Number(button.dataset.exceptionId) });
        if (data && data.success) {
            renderAvailability(data);
            toast(data.message || "Schedule change removed.");
        } else {
            toast((data && data.message) || "Could not remove the schedule change.", "error");
            button.disabled = false;
        }
    });

    byId("officeNotificationBtn").addEventListener("click", function () {
        const willOpen = byId("officeNotificationPanel").hidden;
        byId("officeNotificationPanel").hidden = !willOpen;
        byId("officeNotificationBtn").setAttribute("aria-expanded", String(willOpen));
        byId("officeProfileMenu").hidden = true;
    });
    byId("closeOfficeNotificationsBtn").addEventListener("click", function () { byId("officeNotificationPanel").hidden = true; });
    byId("markOfficeNotificationsReadBtn").addEventListener("click", async function () {
        const data = await postJson("office_notifications.php", { action: "mark_all_read" });
        if (data && data.success) {
            state.unread = 0;
            state.notifications.forEach(function (item) { item.read_at = item.read_at || new Date().toISOString(); });
            renderNotifications();
        }
    });
    byId("officeNotificationList").addEventListener("click", async function (event) {
        const button = event.target.closest("button[data-notification-id]");
        if (!button) {
            return;
        }
        await postJson("office_notifications.php", { action: "mark_read", notification_id: Number(button.dataset.notificationId) });
        if (button.dataset.appointmentId) {
            byId("officeNotificationPanel").hidden = true;
            openAppointment(Number(button.dataset.appointmentId));
        }
        loadOfficeData(true);
    });

    byId("officeProfileBtn").addEventListener("click", function () {
        const willOpen = byId("officeProfileMenu").hidden;
        byId("officeProfileMenu").hidden = !willOpen;
        byId("officeProfileBtn").setAttribute("aria-expanded", String(willOpen));
        byId("officeNotificationPanel").hidden = true;
    });
    byId("editOfficeProfileBtn").addEventListener("click", openProfileEditor);
    byId("closeOfficeProfileBtn").addEventListener("click", closeProfileEditor);
    byId("cancelOfficeProfileBtn").addEventListener("click", closeProfileEditor);
    byId("officeProfileImage").addEventListener("change", function () {
        clearProfilePreviewUrl();
        const file = this.files && this.files[0];
        if (!file) {
            setProfileImage("officeProfilePreview", (state.profile && state.profile.profile_image_url) || "", state.profileImageVersion);
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            this.value = "";
            showError("officeProfileError", "Profile photo must be 2 MB or smaller.");
            return;
        }
        if (["image/jpeg", "image/png", "image/webp"].indexOf(file.type) === -1) {
            this.value = "";
            showError("officeProfileError", "Choose a JPG, PNG, or WebP image.");
            return;
        }
        showError("officeProfileError", "");
        byId("officeRemoveProfileImage").checked = false;
        state.profilePreviewUrl = URL.createObjectURL(file);
        setProfileImage("officeProfilePreview", state.profilePreviewUrl, 0);
    });
    byId("officeRemoveProfileImage").addEventListener("change", function () {
        if (this.checked) {
            clearProfilePreviewUrl();
            byId("officeProfileImage").value = "";
            setProfileImage("officeProfilePreview", "", 0);
        } else {
            setProfileImage("officeProfilePreview", (state.profile && state.profile.profile_image_url) || "", state.profileImageVersion);
        }
    });
    byId("officeProfileForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        showError("officeProfileError", "");
        const saveButton = byId("saveOfficeProfileBtn");
        const formData = new FormData();
        formData.append("display_name", byId("officeProfileDisplayName").value.trim());
        formData.append("remove_photo", byId("officeRemoveProfileImage").checked ? "1" : "0");
        const file = byId("officeProfileImage").files[0];
        if (file) {
            formData.append("profile_image", file);
        }
        saveButton.disabled = true;
        saveButton.textContent = "Saving…";
        try {
            const data = await fetchJson("staff_profile.php", { method: "POST", body: formData });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not update the profile.");
            }
            state.profile = data.profile || state.profile;
            state.profileImageVersion = Date.now();
            PhoneTrackerAuth.setSessionFromLogin({
                role: PhoneTrackerAuth.getRole(),
                user_id: PhoneTrackerAuth.getUserId(),
                username: PhoneTrackerAuth.getUsername(),
                display_name: state.profile.display_name,
            });
            renderProfile();
            closeProfileEditor();
            toast(data.message || "Profile updated.");
        } catch (error) {
            showError("officeProfileError", error.message || "Could not update the profile.");
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = "Save profile";
        }
    });
    byId("officeLogoutBtn").addEventListener("click", function () {
        PhoneTrackerAuth.logout().then(function () { window.location.href = "login.html"; });
    });

    ["officeAppointmentDialog", "officeDeclineDialog", "officeRescheduleDialog", "officeExceptionDialog", "officeProfileDialog"].forEach(function (id) {
        const dialog = byId(id);
        dialog.addEventListener("click", function (event) {
            if (event.target === dialog) {
                if (id === "officeProfileDialog") {
                    closeProfileEditor();
                } else {
                    dialog.close();
                }
            }
        });
    });

    setText("officeProfileName", PhoneTrackerAuth.getDisplayName() || PhoneTrackerAuth.getUsername() || "Office Personnel");
    setText("officeProfileUsername", PhoneTrackerAuth.getUsername() || "office");
    setFutureMinimums();
    const initialView = String(window.location.hash || "#dashboard").slice(1);
    switchView(initialView);
    loadOfficeData(false);
    window.addEventListener("focus", function () { loadOfficeData(true); });
    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
            loadOfficeData(true);
        }
    });
    window.setInterval(function () { loadOfficeData(true); }, 30000);
})();
