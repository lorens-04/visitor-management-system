package ph.edu.isatu.visitor.service

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import androidx.core.app.NotificationCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import ph.edu.isatu.visitor.MainActivity
import ph.edu.isatu.visitor.R
import ph.edu.isatu.visitor.VisitorApplication

class VisitorMessagingService : FirebaseMessagingService() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        val repository = (application as VisitorApplication).repository
        if (repository.signedIn.value) {
            scope.launch { runCatching { repository.registerDevice(token) } }
        }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val title = message.notification?.title ?: message.data["title"] ?: "Appointment update"
        val body = message.notification?.body ?: message.data["message"] ?: "Open the app to see the latest update."
        val appointmentId = message.data["appointment_id"]?.toLongOrNull() ?: 0L
        showNotification(title, body, appointmentId)
    }

    private fun showNotification(title: String, body: String, appointmentId: Long) {
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_ID,
                getString(R.string.notifications_channel_name),
                NotificationManager.IMPORTANCE_DEFAULT,
            ),
        )
        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
            if (appointmentId > 0) putExtra("appointment_id", appointmentId)
        }
        val pendingIntent = PendingIntent.getActivity(
            this,
            appointmentId.toInt(),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .build()
        manager.notify((System.currentTimeMillis() % Int.MAX_VALUE).toInt(), notification)
    }

    companion object {
        private const val CHANNEL_ID = "appointment_updates"
    }
}
