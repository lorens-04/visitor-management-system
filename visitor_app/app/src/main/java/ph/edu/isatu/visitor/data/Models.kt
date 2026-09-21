package ph.edu.isatu.visitor.data

data class ApiMeta(
    val apiVersion: String = "",
    val requestId: String = "",
)

data class ApiEnvelope<T>(
    val success: Boolean = false,
    val data: T? = null,
    val message: String? = null,
    val errors: Map<String, Any?>? = null,
    val meta: ApiMeta? = null,
)

data class UserDto(
    val id: Long = 0,
    val email: String = "",
    val username: String = "",
    val fullName: String = "",
    val contactNumber: String = "",
    val emailVerified: Boolean = false,
    val role: String = "visitor",
)

data class LoginRequest(
    val identifier: String,
    val password: String,
    val installationId: String,
    val deviceName: String,
)

data class LoginData(
    val accessToken: String = "",
    val tokenType: String = "Bearer",
    val expiresAt: String = "",
    val user: UserDto = UserDto(),
)

data class RegisterRequest(
    val email: String,
    val fullName: String,
    val contactNumber: String,
    val password: String,
    val trackingConsent: Boolean,
    val consentVersion: String,
)

data class RegisterData(
    val user: UserDto = UserDto(),
    val emailVerificationRequired: Boolean = false,
)

data class PasswordResetRequest(val email: String)
data class PasswordResetData(val developmentResetToken: String? = null)
data class ResetPasswordRequest(val email: String, val token: String, val newPassword: String)
data class ProfileData(val user: UserDto = UserDto())
data class ProfileUpdateRequest(val fullName: String, val contactNumber: String)

data class OfficeRuleDto(
    val dayOfWeek: Int = 1,
    val startTime: String = "",
    val endTime: String = "",
    val capacity: Int? = null,
)

data class OfficeDto(
    val code: String = "",
    val name: String = "",
    val acceptingVisitors: Boolean = false,
    val unavailableReason: String = "",
    val availableAgainAt: String? = null,
    val slotDurationMinutes: Int = 30,
    val maximumVisitorsPerSlot: Int = 1,
    val weeklyHours: List<OfficeRuleDto> = emptyList(),
)

data class OfficesData(
    val offices: List<OfficeDto> = emptyList(),
    val timezone: String = "Asia/Manila",
)

data class AvailabilitySlotDto(
    val scheduledStartAt: String = "",
    val scheduledEndAt: String = "",
    val remainingCapacity: Int = 0,
)

data class AvailabilityData(
    val officeCode: String = "",
    val officeName: String = "",
    val date: String = "",
    val timezone: String = "Asia/Manila",
    val slotDurationMinutes: Int = 30,
    val acceptingVisitors: Boolean = false,
    val message: String? = null,
    val slots: List<AvailabilitySlotDto> = emptyList(),
)

data class OfficeSummaryDto(val code: String = "", val name: String = "")

data class QrPassDto(
    val payload: String = "",
    val validFrom: String = "",
    val validUntil: String = "",
    val currentlyValid: Boolean = false,
)

data class VisitorSummaryDto(
    val fullName: String = "",
    val email: String = "",
    val contactNumber: String = "",
)

data class RescheduleSlotDto(
    val id: Long = 0,
    val scheduledStartAt: String = "",
    val scheduledEndAt: String = "",
    val selected: Boolean = false,
)

data class RescheduleProposalDto(
    val id: Long = 0,
    val reason: String = "",
    val message: String = "",
    val status: String = "",
    val responseDeadline: String? = null,
    val respondedAt: String? = null,
    val createdAt: String = "",
    val slots: List<RescheduleSlotDto> = emptyList(),
)

data class AppointmentDto(
    val id: Long = 0,
    val registrationCode: String? = null,
    val visitType: String = "appointment",
    val office: OfficeSummaryDto = OfficeSummaryDto(),
    val purpose: String = "",
    val subject: String = "",
    val scheduledStartAt: String = "",
    val scheduledEndAt: String = "",
    val status: String = "pending_approval",
    val statusUpdatedAt: String? = null,
    val rejectionReason: String = "",
    val checkedInAt: String? = null,
    val completedAt: String? = null,
    val createdAt: String = "",
    val qrPass: QrPassDto? = null,
    val visitor: VisitorSummaryDto? = null,
    val additionalDetails: String = "",
    val rescheduleProposal: RescheduleProposalDto? = null,
)

data class AppointmentsData(
    val appointments: List<AppointmentDto> = emptyList(),
    val pagination: PaginationDto = PaginationDto(),
)

data class AppointmentData(val appointment: AppointmentDto = AppointmentDto())
data class PaginationDto(val hasMore: Boolean = false, val nextBeforeId: Long? = null)

data class LocationConsentRequest(val granted: Boolean, val version: String)

data class CreateAppointmentRequest(
    val visitType: String,
    val officeCode: String,
    val purpose: String,
    val subject: String,
    val additionalDetails: String,
    val scheduledStartAt: String,
    val scheduledEndAt: String,
    val deviceName: String,
    val locationConsent: LocationConsentRequest,
)

data class CancelAppointmentRequest(val appointmentId: Long, val reason: String)
data class AppointmentActionData(val appointmentId: Long = 0, val status: String = "")
data class RescheduleResponseRequest(
    val proposalId: Long,
    val action: String,
    val selectedSlotId: Long? = null,
)

data class NotificationDto(
    val id: Long = 0,
    val appointmentId: Long? = null,
    val type: String = "",
    val title: String = "",
    val message: String = "",
    val data: Map<String, Any?>? = null,
    val readAt: String? = null,
    val createdAt: String = "",
)

data class NotificationsData(
    val notifications: List<NotificationDto> = emptyList(),
    val unreadCount: Int = 0,
    val pagination: PaginationDto = PaginationDto(),
)

data class NotificationReadRequest(val notificationId: Long? = null, val markAll: Boolean = false)
data class UpdatedData(val updated: Int = 0)

data class DeviceRegistrationRequest(
    val installationId: String,
    val fcmToken: String,
    val appVersion: String,
    val deviceModel: String,
)

data class DeviceUnregisterRequest(val installationId: String)
data class DeviceData(val deviceId: Long = 0, val queuedNotifications: Int = 0)

data class ConsentDto(
    val id: Long = 0,
    val version: String = "",
    val consentedAt: String? = null,
    val withdrawnAt: String? = null,
    val active: Boolean = false,
)

data class ConsentData(
    val consent: ConsentDto? = null,
    val consentId: Long? = null,
    val active: Boolean? = null,
    val withdrawnRecords: Int? = null,
)

data class ConsentActionRequest(
    val appointmentId: Long,
    val action: String,
    val consentVersion: String? = null,
)

data class TrackingPolicyDto(
    val uploadIntervalSeconds: Int = 15,
    val offlineUploadGraceHours: Int = 24,
    val maximumBatchPoints: Int = 100,
    val retentionDays: Int = 90,
)

data class TrackingSessionDto(
    val id: Long = 0,
    val clientSessionId: String = "",
    val installationId: String = "",
    val startedAt: String = "",
    val lastUploadAt: String? = null,
    val endedAt: String? = null,
    val endedReason: String? = null,
    val active: Boolean = false,
)

data class TrackingData(
    val appointmentId: Long = 0,
    val appointmentStatus: String = "",
    val trackingAllowed: Boolean = false,
    val session: TrackingSessionDto? = null,
    val policy: TrackingPolicyDto = TrackingPolicyDto(),
    val serverTime: String? = null,
)

data class TrackingActionRequest(
    val appointmentId: Long,
    val action: String,
    val clientSessionId: String? = null,
)

data class LocationPointRequest(
    val clientEventId: String,
    val latitude: Double,
    val longitude: Double,
    val accuracy: Double?,
    val capturedAt: String,
)

data class LocationBatchRequest(
    val trackingSessionId: Long,
    val points: List<LocationPointRequest>,
)

data class LocationBatchData(
    val trackingSessionId: Long = 0,
    val accepted: Int = 0,
    val duplicates: Int = 0,
    val serverTime: String = "",
)
