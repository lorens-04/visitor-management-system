package ph.edu.isatu.visitor.ui

import android.Manifest
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.os.Build
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter
import kotlinx.coroutines.delay
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.TrackingData
import ph.edu.isatu.visitor.service.VisitorTrackingService

@Composable
fun AppointmentDetailScreen(
    appointment: AppointmentDto,
    tracking: TrackingData?,
    busy: Boolean,
    viewModel: AppViewModel,
) {
    BackHandler(onBack = viewModel::closeAppointment)
    val context = LocalContext.current
    var showCancel by remember { mutableStateOf(false) }
    var selectedSlotId by remember { mutableLongStateOf(0L) }
    var trackingRequested by remember(appointment.id) { mutableStateOf(false) }
    var locationPermissionMissing by remember(appointment.id) { mutableStateOf(false) }
    val permissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { permissions ->
        if (permissions[Manifest.permission.ACCESS_FINE_LOCATION] == true) {
            VisitorTrackingService.start(context, appointment.id)
            trackingRequested = true
            locationPermissionMissing = false
        } else {
            locationPermissionMissing = true
        }
    }
    val requestTrackingPermissions = {
        val permissions = buildList {
            add(Manifest.permission.ACCESS_FINE_LOCATION)
            add(Manifest.permission.ACCESS_COARSE_LOCATION)
            if (Build.VERSION.SDK_INT >= 33) add(Manifest.permission.POST_NOTIFICATIONS)
        }.toTypedArray()
        permissionLauncher.launch(permissions)
    }

    LaunchedEffect(appointment.id, appointment.status) {
        if (appointment.status == "approved" && appointment.qrPass != null) {
            while (true) {
                delay(3_000)
                viewModel.pollSelectedAppointment(appointment.id)
            }
        }

        if (appointment.status == "checked_in") {
            val preciseLocationGranted = ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.ACCESS_FINE_LOCATION,
            ) == PackageManager.PERMISSION_GRANTED
            if (preciseLocationGranted) {
                VisitorTrackingService.start(context, appointment.id)
                trackingRequested = true
                delay(1_500)
                viewModel.refreshTrackingSilently(appointment.id)
            } else {
                locationPermissionMissing = true
                val permissions = buildList {
                    add(Manifest.permission.ACCESS_FINE_LOCATION)
                    add(Manifest.permission.ACCESS_COARSE_LOCATION)
                    if (Build.VERSION.SDK_INT >= 33) add(Manifest.permission.POST_NOTIFICATIONS)
                }.toTypedArray()
                permissionLauncher.launch(permissions)
            }
            while (true) {
                delay(10_000)
                viewModel.pollSelectedAppointment(appointment.id)
            }
        }
    }

    if (appointment.status == "checked_in") {
        TrackingMapScreen(
            appointment = appointment,
            tracking = tracking,
            trackingStarting = trackingRequested,
            locationPermissionMissing = locationPermissionMissing,
            onBack = viewModel::closeAppointment,
            onRequestLocationPermission = requestTrackingPermissions,
            onRefresh = {
                viewModel.pollSelectedAppointment(appointment.id)
                viewModel.refreshTrackingSilently(appointment.id)
            },
            onWithdrawConsent = {
                VisitorTrackingService.stop(context, appointment.id)
                viewModel.withdrawConsent(appointment.id)
            },
        )
        return
    }

    DetailScaffold(
        if (appointment.qrPass != null) "Visitor pass" else "Visit details",
        viewModel::closeAppointment,
    ) { outerModifier ->
        LazyColumn(
            modifier = outerModifier.fillMaxSize(),
            contentPadding = PaddingValues(18.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            item {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(appointment.office.name, style = MaterialTheme.typography.headlineMedium)
                        appointment.registrationCode?.let { Text(it, color = MutedInk) }
                    }
                    IconButton(onClick = viewModel::refreshSelectedAppointment, enabled = !busy) {
                        Icon(Icons.Rounded.Refresh, "Refresh")
                    }
                }
            }
            item { StatusPill(appointment.status) }
            if (appointment.status == "pending_approval") {
                item {
                    Card(
                        colors = CardDefaults.cardColors(containerColor = YellowSurface),
                        border = BorderStroke(1.dp, Color(0xFFF4D86C)),
                        shape = RoundedCornerShape(18.dp),
                    ) {
                        Row(Modifier.padding(18.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            Icon(Icons.Rounded.HourglassTop, contentDescription = null, tint = Color(0xFF9A6700))
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                Text("Request submitted", style = MaterialTheme.typography.titleMedium)
                                Text(
                                    "The office is reviewing your request. Its decision will appear in Notifications.",
                                    color = MutedInk,
                                )
                            }
                        }
                    }
                }
            }
            item {
                DetailCard {
                    Text("Visit information", style = MaterialTheme.typography.titleLarge, color = IsatuBlueDark)
                    HorizontalDivider(color = BorderSoft)
                    DetailLine("Visitor type", if (appointment.visitType == "walk_in") "Walk-in" else "Appointment")
                    DetailLine("Destination", appointment.office.name)
                    DetailLine("Schedule", formatDateTime(appointment.scheduledStartAt))
                    DetailLine("Until", formatDateTime(appointment.scheduledEndAt))
                    DetailLine("Purpose", appointment.purpose)
                    DetailLine("Subject", appointment.subject)
                    if (appointment.additionalDetails.isNotBlank()) DetailLine("Details", appointment.additionalDetails)
                }
            }
            if (appointment.rejectionReason.isNotBlank()) {
                item {
                    Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFFFE8E6)), shape = RoundedCornerShape(16.dp)) {
                        Column(Modifier.padding(16.dp)) {
                            Text("Office response", fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.error)
                            Text(appointment.rejectionReason)
                        }
                    }
                }
            }
            appointment.qrPass?.let { pass ->
                item {
                    QrPassCard(
                        appointment = appointment,
                        payload = pass.token.ifBlank { pass.payload },
                        validFrom = pass.validFrom,
                        validUntil = pass.validUntil,
                        currentlyValid = pass.currentlyValid,
                    )
                }
            }
            appointment.rescheduleProposal?.takeIf { it.status == "pending" }?.let { proposal ->
                item {
                    Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFFFF8DB)), shape = RoundedCornerShape(18.dp)) {
                        Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            Text("The office suggested another time", style = MaterialTheme.typography.titleLarge)
                            Text(proposal.reason, fontWeight = FontWeight.SemiBold)
                            if (proposal.message.isNotBlank()) Text(proposal.message, color = MutedInk)
                            proposal.responseDeadline?.let { Text("Respond by ${formatDateTime(it)}", color = MutedInk) }
                        }
                    }
                }
                items(proposal.slots, key = { it.id }) { slot ->
                    Card(
                        modifier = Modifier.fillMaxWidth(),
                        colors = CardDefaults.cardColors(containerColor = if (selectedSlotId == slot.id) Color(0xFFDCEBFF) else Color.White),
                    ) {
                        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(selectedSlotId == slot.id, { selectedSlotId = slot.id })
                            Text(formatDateTime(slot.scheduledStartAt), fontWeight = FontWeight.SemiBold)
                        }
                    }
                }
                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        OutlinedButton(
                            onClick = { viewModel.respondToReschedule(proposal.id, "decline", null) },
                            modifier = Modifier.weight(1f),
                            enabled = !busy,
                        ) { Text("Decline") }
                        PrimaryButton(
                            "Accept time",
                            { viewModel.respondToReschedule(proposal.id, "accept", selectedSlotId) },
                            Modifier.weight(1f),
                            !busy && selectedSlotId > 0,
                        )
                    }
                }
            }
            if (appointment.status in listOf("pending_approval", "approved", "reschedule_proposed")) {
                item {
                    OutlinedButton(
                        onClick = { showCancel = true },
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        enabled = !busy,
                    ) { Text("Cancel appointment", color = MaterialTheme.colorScheme.error) }
                }
            }
            item { Spacer(Modifier.height(18.dp)) }
        }
    }

    if (showCancel) {
        CancelAppointmentDialog(
            onDismiss = { showCancel = false },
            onConfirm = { reason ->
                viewModel.cancelAppointment(appointment.id, reason)
                showCancel = false
            },
        )
    }
}

@Composable
private fun DetailCard(content: @Composable ColumnScope.() -> Unit) {
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            content()
        }
    }
}

@Composable
private fun DetailLine(label: String, value: String) {
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(label, color = MutedInk, style = MaterialTheme.typography.bodyMedium)
        Text(value)
    }
}

@Composable
private fun QrPassCard(
    appointment: AppointmentDto,
    payload: String,
    validFrom: String,
    validUntil: String,
    currentlyValid: Boolean,
) {
    val bitmap = remember(payload) { generateQrBitmap(payload) }
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(20.dp),
    ) {
        Column(
            Modifier.fillMaxWidth().padding(20.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Box(
                Modifier.size(48.dp).background(BlueSurface, RoundedCornerShape(14.dp)),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Rounded.QrCode2, contentDescription = null, tint = IsatuBlue)
            }
            Text("VISITOR PASS", style = MaterialTheme.typography.titleLarge, color = IsatuBlueDark)
            Text(
                if (currentlyValid) "Ready to scan"
                else if (appointment.visitType == "walk_in") "This walk-in pass is no longer active"
                else "Keep this pass ready for your scheduled arrival",
                color = if (currentlyValid) IsatuGreen else IsatuOrange,
                fontWeight = FontWeight.SemiBold,
            )
            appointment.registrationCode?.let {
                Text("Reference $it", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
            }
            HorizontalDivider(color = BorderSoft)
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                DetailLine("Visitor", appointment.visitor?.fullName.orEmpty().ifBlank { "Registered visitor" })
                DetailLine("Visitor type", if (appointment.visitType == "walk_in") "Walk-in" else "Appointment")
                DetailLine("Destination", appointment.office.name)
                DetailLine("Purpose", appointment.purpose)
                DetailLine("Subject / concern", appointment.subject)
                DetailLine("Schedule", formatDateTime(appointment.scheduledStartAt))
            }
            bitmap?.let {
                Card(
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                    border = BorderStroke(8.dp, Color.White),
                    shape = RoundedCornerShape(12.dp),
                ) {
                    Image(it.asImageBitmap(), "Visitor QR pass", Modifier.size(238.dp))
                }
            }
            Text(
                "Security can scan this pass from ${formatDateTime(validFrom)} until ${formatDateTime(validUntil)}.",
                color = MutedInk,
                textAlign = TextAlign.Center,
            )
            Text("Do not share screenshots of your pass.", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

@Composable
private fun CancelAppointmentDialog(onDismiss: () -> Unit, onConfirm: (String) -> Unit) {
    var reason by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Cancel this appointment?") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("The office will be notified and the slot will become available to another visitor.")
                OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Reason (optional)") }, minLines = 2)
            }
        },
        confirmButton = { TextButton(onClick = { onConfirm(reason) }) { Text("Cancel appointment", color = MaterialTheme.colorScheme.error) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Keep appointment") } },
    )
}

private fun generateQrBitmap(payload: String): Bitmap? = runCatching {
    val matrix = QRCodeWriter().encode(payload, BarcodeFormat.QR_CODE, 720, 720)
    Bitmap.createBitmap(matrix.width, matrix.height, Bitmap.Config.ARGB_8888).apply {
        for (x in 0 until matrix.width) {
            for (y in 0 until matrix.height) {
                setPixel(x, y, if (matrix[x, y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE)
            }
        }
    }
}.getOrNull()
