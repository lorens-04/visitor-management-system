package ph.edu.isatu.visitor

import com.google.gson.FieldNamingPolicy
import com.google.gson.GsonBuilder
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Test
import ph.edu.isatu.visitor.data.ApiEnvelope
import ph.edu.isatu.visitor.data.AppointmentData
import ph.edu.isatu.visitor.ui.statusLabel

class ApiModelTest {
    private val gson = GsonBuilder()
        .setFieldNamingPolicy(FieldNamingPolicy.LOWER_CASE_WITH_UNDERSCORES)
        .create()

    @Test
    fun pendingAppointmentDoesNotNeedQrData() {
        val json = """
            {
              "success": true,
              "data": {
                "appointment": {
                  "id": 18,
                  "office": {"code": "IT", "name": "IT Department"},
                  "subject": "Enrollment concern",
                  "status": "pending_approval",
                  "scheduled_start_at": "2026-09-21 09:00:00",
                  "scheduled_end_at": "2026-09-21 09:30:00",
                  "qr_pass": null
                }
              }
            }
        """.trimIndent()
        val type = com.google.gson.reflect.TypeToken.getParameterized(
            ApiEnvelope::class.java,
            AppointmentData::class.java,
        ).type
        val envelope = gson.fromJson<ApiEnvelope<AppointmentData>>(json, type)
        assertEquals(18L, envelope.data?.appointment?.id)
        assertEquals("IT Department", envelope.data?.appointment?.office?.name)
        assertNull(envelope.data?.appointment?.qrPass)
        assertFalse(envelope.data?.appointment?.qrPass?.currentlyValid ?: false)
    }

    @Test
    fun approvedWalkInIncludesImmediateQrPass() {
        val json = """
            {
              "success": true,
              "data": {
                "appointment": {
                  "id": 19,
                  "visit_type": "walk_in",
                  "office": {"code": "IT", "name": "IT Department"},
                  "subject": "Technical assistance",
                  "status": "approved",
                  "scheduled_start_at": "2026-09-20 10:00:00",
                  "scheduled_end_at": "2026-09-20 11:00:00",
                  "qr_pass": {
                    "payload": "isatu-visitor://pass?token=test",
                    "valid_from": "2026-09-20 09:30:00",
                    "valid_until": "2026-09-20 11:00:00",
                    "currently_valid": true
                  }
                }
              }
            }
        """.trimIndent()
        val type = com.google.gson.reflect.TypeToken.getParameterized(
            ApiEnvelope::class.java,
            AppointmentData::class.java,
        ).type
        val appointment = gson.fromJson<ApiEnvelope<AppointmentData>>(json, type).data?.appointment
        assertEquals("walk_in", appointment?.visitType)
        assertEquals("approved", appointment?.status)
        assertEquals(true, appointment?.qrPass?.currentlyValid)
    }

    @Test
    fun closedWindowUsesFriendlyProductLanguage() {
        assertEquals("Appointment done", statusLabel("window_closed"))
        assertEquals("Appointment done", statusLabel("completed"))
    }
}
