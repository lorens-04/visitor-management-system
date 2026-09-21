package ph.edu.isatu.visitor.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.async
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import ph.edu.isatu.visitor.BuildConfig
import ph.edu.isatu.visitor.VisitorApplication
import ph.edu.isatu.visitor.data.ApiException
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.AvailabilityData
import ph.edu.isatu.visitor.data.CreateAppointmentRequest
import ph.edu.isatu.visitor.data.LocationConsentRequest
import ph.edu.isatu.visitor.data.NotificationDto
import ph.edu.isatu.visitor.data.OfficeDto
import ph.edu.isatu.visitor.data.TrackingData
import ph.edu.isatu.visitor.data.UserDto
import ph.edu.isatu.visitor.data.VisitorRepository

data class VisitorUiState(
    val signedIn: Boolean = false,
    val initializing: Boolean = true,
    val busy: Boolean = false,
    val user: UserDto? = null,
    val offices: List<OfficeDto> = emptyList(),
    val appointments: List<AppointmentDto> = emptyList(),
    val notifications: List<NotificationDto> = emptyList(),
    val unreadCount: Int = 0,
    val selectedAppointment: AppointmentDto? = null,
    val availability: AvailabilityData? = null,
    val tracking: TrackingData? = null,
    val error: String? = null,
    val message: String? = null,
)

class AppViewModel(application: Application) : AndroidViewModel(application) {
    private val repository: VisitorRepository = (application as VisitorApplication).repository
    private val _state = MutableStateFlow(VisitorUiState())
    val state: StateFlow<VisitorUiState> = _state.asStateFlow()

    init {
        viewModelScope.launch {
            repository.signedIn.collectLatest { signedIn ->
                if (signedIn) {
                    _state.update { it.copy(signedIn = true, initializing = true) }
                    refreshAll(initial = true)
                    syncFirebaseToken()
                } else {
                    _state.value = VisitorUiState(signedIn = false, initializing = false)
                }
            }
        }
    }

    fun login(email: String, password: String) = launchTask {
        repository.login(email.trim(), password)
    }

    fun register(email: String, name: String, contact: String, password: String) = launchTask {
        repository.register(email.trim(), name.trim(), contact.trim(), password)
        repository.login(email.trim(), password)
    }

    fun requestPasswordReset(email: String) = launchTask {
        val result = repository.requestPasswordReset(email.trim())
        val localToken = result.developmentResetToken
        _state.update {
            it.copy(
                message = localToken?.let { token -> "Local test reset token: $token" }
                    ?: "If the account exists, reset instructions were sent.",
            )
        }
    }

    fun resetPassword(email: String, token: String, password: String) = launchTask {
        repository.resetPassword(email.trim(), token.trim(), password)
        _state.update { it.copy(message = "Password updated. Sign in with your new password.") }
    }

    fun refreshAll(initial: Boolean = false) {
        viewModelScope.launch {
            if (!initial) _state.update { it.copy(busy = true, error = null) }
            try {
                val profile = async { repository.profile() }
                val offices = async { repository.offices() }
                val appointments = async { repository.appointments() }
                val notifications = async { repository.notifications() }
                val profileData = profile.await()
                val officeData = offices.await()
                val appointmentData = appointments.await()
                val notificationData = notifications.await()
                _state.update {
                    it.copy(
                        signedIn = true,
                        initializing = false,
                        busy = false,
                        user = profileData,
                        offices = officeData,
                        appointments = appointmentData,
                        notifications = notificationData.notifications,
                        unreadCount = notificationData.unreadCount,
                        error = null,
                    )
                }
            } catch (error: Exception) {
                if (error is ApiException && error.statusCode == 401) return@launch
                _state.update {
                    it.copy(initializing = false, busy = false, error = readableError(error))
                }
            }
        }
    }

    fun loadAvailability(officeCode: String, date: String) = launchTask {
        val result = repository.availability(officeCode, date)
        _state.update { it.copy(availability = result) }
    }

    fun clearAvailability() = _state.update { it.copy(availability = null) }

    fun createAppointment(
        visitType: String,
        officeCode: String,
        purpose: String,
        subject: String,
        details: String,
        start: String,
        end: String,
    ) = launchTask {
        val appointment = repository.createAppointment(
            CreateAppointmentRequest(
                visitType = visitType,
                officeCode = officeCode,
                purpose = purpose.trim(),
                subject = subject.trim(),
                additionalDetails = details.trim(),
                scheduledStartAt = start,
                scheduledEndAt = end,
                deviceName = repository.installationStore.deviceName,
                locationConsent = LocationConsentRequest(true, BuildConfig.CONSENT_VERSION),
            ),
        )
        _state.update {
            it.copy(
                selectedAppointment = appointment,
                availability = null,
                message = if (visitType == "walk_in") {
                    "Walk-in pass ready. Present it to Security with a valid ID."
                } else {
                    "Appointment request submitted for office review."
                },
            )
        }
        refreshAppointments()
    }

    fun openAppointment(id: Long) = launchTask {
        val appointment = repository.appointment(id)
        val tracking = if (appointment.status == "checked_in") {
            runCatching { repository.tracking(id) }.getOrNull()
        } else null
        _state.update { it.copy(selectedAppointment = appointment, tracking = tracking) }
    }

    fun closeAppointment() = _state.update { it.copy(selectedAppointment = null, tracking = null) }

    fun refreshSelectedAppointment() {
        _state.value.selectedAppointment?.id?.let(::openAppointment)
    }

    fun cancelAppointment(id: Long, reason: String) = launchTask {
        repository.cancelAppointment(id, reason.trim())
        _state.update { it.copy(message = "Appointment cancelled.", selectedAppointment = null) }
        refreshAppointments()
    }

    fun respondToReschedule(proposalId: Long, action: String, slotId: Long?) = launchTask {
        repository.respondToReschedule(proposalId, action, slotId)
        _state.update {
            it.copy(
                message = if (action == "accept") "Schedule accepted. Your visitor pass is ready."
                else "Proposed schedules declined.",
                selectedAppointment = null,
            )
        }
        refreshAppointments()
    }

    fun refreshTracking(id: Long) = launchTask {
        _state.update { it.copy(tracking = repository.tracking(id)) }
    }

    fun withdrawConsent(id: Long) = launchTask {
        repository.withdrawConsent(id)
        _state.update { it.copy(message = "Location consent withdrawn and sharing stopped.") }
        openAppointment(id)
    }

    fun markNotificationRead(id: Long) = launchTask(showBusy = false) {
        repository.markNotificationRead(id)
        _state.update { current ->
            current.copy(
                notifications = current.notifications.map {
                    if (it.id == id) it.copy(readAt = "read") else it
                },
                unreadCount = (current.unreadCount - 1).coerceAtLeast(0),
            )
        }
    }

    fun markAllNotificationsRead() = launchTask(showBusy = false) {
        repository.markNotificationRead(all = true)
        _state.update { current ->
            current.copy(
                notifications = current.notifications.map { it.copy(readAt = it.readAt ?: "read") },
                unreadCount = 0,
            )
        }
    }

    fun updateProfile(name: String, contact: String) = launchTask {
        val user = repository.updateProfile(name.trim(), contact.trim())
        _state.update { it.copy(user = user, message = "Profile updated.") }
    }

    fun logout() = launchTask {
        repository.logout()
    }

    fun clearBanner() = _state.update { it.copy(error = null, message = null) }

    private fun refreshAppointments() {
        viewModelScope.launch {
            runCatching { repository.appointments() }.onSuccess { appointments ->
                _state.update { it.copy(appointments = appointments) }
            }
        }
    }

    private fun syncFirebaseToken() {
        val application = getApplication<Application>()
        if (FirebaseApp.getApps(application).isEmpty()) return
        FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
            viewModelScope.launch { runCatching { repository.registerDevice(token) } }
        }
    }

    private fun launchTask(showBusy: Boolean = true, block: suspend () -> Unit) {
        viewModelScope.launch {
            if (showBusy) _state.update { it.copy(busy = true, error = null, message = null) }
            try {
                block()
                if (showBusy) _state.update { it.copy(busy = false) }
            } catch (error: Exception) {
                _state.update { it.copy(busy = false, error = readableError(error)) }
            }
        }
    }

    private fun readableError(error: Exception): String {
        val apiError = error as? ApiException
        return apiError?.fieldErrors?.values?.firstOrNull { it.isNotBlank() }
            ?: apiError?.message
            ?: error.message
            ?: "Something went wrong. Check your connection and try again."
    }

    class Factory(private val application: Application) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = AppViewModel(application) as T
    }
}
