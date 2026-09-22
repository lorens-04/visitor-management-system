package ph.edu.isatu.visitor.data

import android.location.Location
import kotlinx.coroutines.flow.StateFlow
import ph.edu.isatu.visitor.BuildConfig
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.UUID

class VisitorRepository(
    private val apiClient: ApiClient,
    private val tokenStore: SecureTokenStore,
    val installationStore: InstallationStore,
    private val locationQueue: LocationQueueDao,
) {
    val signedIn: StateFlow<Boolean> = tokenStore.signedIn

    suspend fun register(email: String, fullName: String, contactNumber: String, password: String): RegisterData =
        apiClient.requireData(
            apiClient.api.register(
                RegisterRequest(email, fullName, contactNumber, password, true, BuildConfig.CONSENT_VERSION),
            ),
        )

    suspend fun login(identifier: String, password: String): UserDto {
        val result = apiClient.requireData(
            apiClient.api.login(
                LoginRequest(identifier, password, installationStore.installationId, installationStore.deviceName),
            ),
        )
        tokenStore.saveToken(result.accessToken)
        return result.user
    }

    suspend fun logout() {
        try {
            runCatching { apiClient.requireData(apiClient.api.unregisterDevice(DeviceUnregisterRequest(installationStore.installationId))) }
            runCatching { apiClient.requireData(apiClient.api.logout()) }
        } finally {
            tokenStore.clear()
            installationStore.clearActiveTracking()
        }
    }

    suspend fun requestPasswordReset(email: String): PasswordResetData =
        apiClient.requireData(apiClient.api.requestPasswordReset(PasswordResetRequest(email)))

    suspend fun resetPassword(email: String, token: String, password: String) {
        apiClient.requireData(apiClient.api.resetPassword(ResetPasswordRequest(email, token, password)))
    }

    suspend fun profile(): UserDto = apiClient.requireData(apiClient.api.me()).user

    suspend fun updateProfile(fullName: String, contactNumber: String): UserDto =
        apiClient.requireData(apiClient.api.updateProfile(ProfileUpdateRequest(fullName, contactNumber))).user

    suspend fun offices(): List<OfficeDto> = apiClient.requireData(apiClient.api.offices()).offices

    suspend fun availability(officeCode: String, date: String): AvailabilityData =
        apiClient.requireData(apiClient.api.availability(officeCode, date))

    suspend fun appointments(): List<AppointmentDto> =
        apiClient.requireData(apiClient.api.appointments()).appointments

    suspend fun appointment(id: Long): AppointmentDto =
        apiClient.requireData(apiClient.api.appointment(id)).appointment

    suspend fun createAppointment(request: CreateAppointmentRequest): AppointmentDto =
        apiClient.requireData(apiClient.api.createAppointment(request)).appointment

    suspend fun cancelAppointment(id: Long, reason: String) {
        apiClient.requireData(apiClient.api.cancelAppointment(CancelAppointmentRequest(id, reason)))
    }

    suspend fun respondToReschedule(proposalId: Long, action: String, slotId: Long?) {
        apiClient.requireData(
            apiClient.api.respondToReschedule(RescheduleResponseRequest(proposalId, action, slotId)),
        )
    }

    suspend fun notifications(): NotificationsData = apiClient.requireData(apiClient.api.notifications())

    suspend fun markNotificationRead(id: Long? = null, all: Boolean = false) {
        apiClient.requireData(apiClient.api.markNotificationRead(NotificationReadRequest(id, all)))
    }

    suspend fun registerDevice(fcmToken: String) {
        apiClient.requireData(
            apiClient.api.registerDevice(
                DeviceRegistrationRequest(
                    installationId = installationStore.installationId,
                    fcmToken = fcmToken,
                    appVersion = BuildConfig.VERSION_NAME,
                    deviceModel = installationStore.deviceName,
                ),
            ),
        )
    }

    suspend fun tracking(appointmentId: Long): TrackingData =
        apiClient.requireData(apiClient.api.tracking(appointmentId))

    suspend fun startTracking(appointmentId: Long): TrackingData {
        val result = apiClient.requireData(
            apiClient.api.updateTracking(
                TrackingActionRequest(appointmentId, "start", UUID.randomUUID().toString()),
            ),
        )
        result.session?.let { installationStore.saveActiveTracking(appointmentId, it.id) }
        return result
    }

    suspend fun stopTracking(appointmentId: Long) {
        runCatching {
            apiClient.requireData(apiClient.api.updateTracking(TrackingActionRequest(appointmentId, "stop")))
        }
        installationStore.clearActiveTracking()
    }

    suspend fun withdrawConsent(appointmentId: Long) {
        apiClient.requireData(apiClient.api.updateConsent(ConsentActionRequest(appointmentId, "withdraw")))
        installationStore.clearActiveTracking()
    }

    suspend fun queueLocation(appointmentId: Long, sessionId: Long, location: Location) {
        locationQueue.insert(
            QueuedLocation(
                appointmentId = appointmentId,
                trackingSessionId = sessionId,
                clientEventId = UUID.randomUUID().toString(),
                latitude = location.latitude,
                longitude = location.longitude,
                accuracy = location.accuracy.takeIf { location.hasAccuracy() }?.toDouble(),
                capturedAt = locationTimestamp(location.time.takeIf { it > 0 } ?: System.currentTimeMillis()),
            ),
        )
    }

    suspend fun flushLocations(sessionId: Long, maximumBatchPoints: Int = 100): Int {
        var uploaded = 0
        while (true) {
            val queued = locationQueue.oldest(sessionId, maximumBatchPoints.coerceIn(1, 100))
            if (queued.isEmpty()) return uploaded
            apiClient.requireData(
                apiClient.api.uploadLocations(
                    LocationBatchRequest(
                        trackingSessionId = sessionId,
                        points = queued.map {
                            LocationPointRequest(
                                clientEventId = it.clientEventId,
                                latitude = it.latitude,
                                longitude = it.longitude,
                                accuracy = it.accuracy,
                                capturedAt = it.capturedAt,
                            )
                        },
                    ),
                ),
            )
            locationQueue.delete(queued.map { it.id })
            uploaded += queued.size
            if (queued.size < maximumBatchPoints.coerceIn(1, 100)) return uploaded
        }
    }

    suspend fun queuedLocationCount(sessionId: Long): Int = locationQueue.count(sessionId)

    private fun locationTimestamp(milliseconds: Long): String =
        SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
            timeZone = TimeZone.getTimeZone("Asia/Manila")
        }.format(Date(milliseconds))
}
