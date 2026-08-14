(function () {
    const ROLE_KEY = "phone_tracker_role";
    const USER_ID_KEY = "phone_tracker_user_id";
    const USERNAME_KEY = "phone_tracker_username";
    const DISPLAY_NAME_KEY = "phone_tracker_display_name";
    const ALLOWED_ROLES = ["security", "visitor", "offices", "admin"];

    try {
        var params = new URLSearchParams(window.location.search);
        var fromQuery = params.get("role");
        if (fromQuery && ALLOWED_ROLES.indexOf(fromQuery) !== -1) {
            sessionStorage.setItem(ROLE_KEY, fromQuery);
            var cleanUrl = window.location.pathname + window.location.hash;
            if (window.location.search) {
                history.replaceState(null, "", cleanUrl);
            }
        }
    } catch (e) {}

    function getRole() {
        return sessionStorage.getItem(ROLE_KEY);
    }

    function setRole(role) {
        sessionStorage.setItem(ROLE_KEY, role);
    }

    function setSessionFromLogin(payload) {
        if (!payload || ALLOWED_ROLES.indexOf(payload.role) === -1) {
            return;
        }
        setRole(payload.role);
        if (payload.user_id != null) {
            sessionStorage.setItem(USER_ID_KEY, String(payload.user_id));
        }
        if (payload.username) {
            sessionStorage.setItem(USERNAME_KEY, payload.username);
        }
        if (payload.display_name != null) {
            sessionStorage.setItem(DISPLAY_NAME_KEY, String(payload.display_name));
        }
    }

    function getUserId() {
        var v = sessionStorage.getItem(USER_ID_KEY);
        return v ? parseInt(v, 10) : null;
    }

    function getUsername() {
        return sessionStorage.getItem(USERNAME_KEY);
    }

    function getDisplayName() {
        return sessionStorage.getItem(DISPLAY_NAME_KEY);
    }

    function clearRole() {
        sessionStorage.removeItem(ROLE_KEY);
        sessionStorage.removeItem(USER_ID_KEY);
        sessionStorage.removeItem(USERNAME_KEY);
        sessionStorage.removeItem(DISPLAY_NAME_KEY);
    }

    function logout() {
        return fetch("logout.php", {
            method: "POST",
            credentials: "same-origin",
        })
            .catch(function () {
                return null;
            })
            .then(function () {
                clearRole();
            });
    }

    /**
     * @param {string[]} allowed
     * @returns {boolean} false if redirecting to login
     */
    function requireRole(allowed) {
        const role = getRole();
        if (!role || allowed.indexOf(role) === -1) {
            window.location.href = "login.html";
            return false;
        }
        return true;
    }

    window.PhoneTrackerAuth = {
        getRole,
        setRole,
        setSessionFromLogin,
        getUserId,
        getUsername,
        getDisplayName,
        clearRole,
        logout,
        requireRole,
    };
})();
