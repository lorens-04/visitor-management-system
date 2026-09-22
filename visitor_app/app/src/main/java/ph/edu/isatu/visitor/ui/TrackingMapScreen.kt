package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Map
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.TrackingData

/**
 * Post-check-in shell for the campus map.
 *
 * The visual structure is intentionally independent from a map vendor. Replace
 * [CampusMapPlaceholder] with GoogleMap/MapLibre once the institutional map key,
 * official campus boundary, and office coordinates are available.
 */
@Composable
fun TrackingMapScreen(
    appointment: AppointmentDto,
    tracking: TrackingData?,
    trackingStarting: Boolean,
    locationPermissionMissing: Boolean,
    onBack: () -> Unit,
    onRequestLocationPermission: () -> Unit,
    onRefresh: () -> Unit,
    onWithdrawConsent: () -> Unit,
) {
    val active = tracking?.session?.active == true || trackingStarting

    DetailScaffold("Campus visit", onBack) { outerModifier ->
        Column(modifier = outerModifier.fillMaxSize().background(AppBackground)) {
            Box(Modifier.fillMaxWidth().weight(1f)) {
                CampusMapPlaceholder(
                    destination = appointment.office.name,
                    modifier = Modifier.fillMaxSize(),
                )

                Surface(
                    modifier = Modifier.fillMaxWidth().padding(16.dp),
                    shape = RoundedCornerShape(14.dp),
                    color = Color.White,
                    shadowElevation = 4.dp,
                ) {
                    Row(
                        modifier = Modifier.padding(horizontal = 14.dp, vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        Icon(Icons.Rounded.LocationOn, contentDescription = null, tint = IsatuBlue)
                        Column(Modifier.weight(1f)) {
                            Text("Destination", style = MaterialTheme.typography.bodySmall, color = MutedInk)
                            Text(
                                appointment.office.name,
                                style = MaterialTheme.typography.titleMedium,
                                maxLines = 1,
                            )
                        }
                        IconButton(onClick = onRefresh) {
                            Icon(Icons.Rounded.Refresh, contentDescription = "Refresh visit status", tint = IsatuBlueDark)
                        }
                    }
                }
            }

            Surface(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp),
                color = Color.White,
                shadowElevation = 8.dp,
            ) {
                Column(
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 18.dp),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                            Text("Destination", color = MutedInk, style = MaterialTheme.typography.bodySmall)
                            Text(appointment.office.name, style = MaterialTheme.typography.titleLarge, color = IsatuBlueDark)
                        }
                        TrackingStatusBadge(active, locationPermissionMissing)
                    }

                    if (locationPermissionMissing) {
                        Text(
                            "Precise location access is off. Allow it so campus tracking can begin.",
                            color = MaterialTheme.colorScheme.error,
                        )
                        PrimaryButton(
                            "Allow location access",
                            onRequestLocationPermission,
                            Modifier.fillMaxWidth(),
                        )
                    } else if (!active) {
                        LinearProgressIndicator(Modifier.fillMaxWidth(), color = IsatuBlue)
                        Text("Starting secure location sharing…", color = MutedInk)
                    } else {
                        Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.Top) {
                            Icon(Icons.Rounded.Info, contentDescription = null, tint = IsatuBlue)
                            Text(
                                "Your position is shared while your campus visit is active. Tracking will stop after a confirmed campus exit or when Security completes the visit.",
                                color = MutedInk,
                                style = MaterialTheme.typography.bodyMedium,
                            )
                        }
                    }

                    TextButton(onClick = onWithdrawConsent, modifier = Modifier.align(Alignment.End)) {
                        Text("Withdraw consent", color = MaterialTheme.colorScheme.error)
                    }
                }
            }
        }
    }
}

@Composable
private fun TrackingStatusBadge(active: Boolean, permissionMissing: Boolean) {
    val label = when {
        permissionMissing -> "Location required"
        active -> "Tracking active"
        else -> "Starting"
    }
    val color = when {
        permissionMissing -> MaterialTheme.colorScheme.error
        active -> IsatuGreen
        else -> IsatuOrange
    }
    Surface(shape = RoundedCornerShape(50), color = color.copy(alpha = 0.12f)) {
        Text(
            label,
            modifier = Modifier.padding(horizontal = 11.dp, vertical = 7.dp),
            color = color,
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.SemiBold,
        )
    }
}

@Composable
private fun CampusMapPlaceholder(destination: String, modifier: Modifier = Modifier) {
    Box(modifier.background(Color(0xFFDCE8F5))) {
        val gridColor = Color(0xFFB9CBE0)
        val boundaryColor = IsatuBlue
        Canvas(Modifier.fillMaxSize()) {
            val spacing = 56.dp.toPx()
            var x = 0f
            while (x <= size.width) {
                drawLine(gridColor, Offset(x, 0f), Offset(x, size.height), strokeWidth = 1.dp.toPx())
                x += spacing
            }
            var y = 0f
            while (y <= size.height) {
                drawLine(gridColor, Offset(0f, y), Offset(size.width, y), strokeWidth = 1.dp.toPx())
                y += spacing
            }
            drawRoundRect(
                color = boundaryColor,
                topLeft = Offset(size.width * 0.12f, size.height * 0.20f),
                size = androidx.compose.ui.geometry.Size(size.width * 0.76f, size.height * 0.62f),
                cornerRadius = androidx.compose.ui.geometry.CornerRadius(28.dp.toPx()),
                style = androidx.compose.ui.graphics.drawscope.Stroke(
                    width = 3.dp.toPx(),
                    pathEffect = PathEffect.dashPathEffect(floatArrayOf(14f, 10f)),
                ),
            )
        }

        Card(
            modifier = Modifier.align(Alignment.Center).padding(24.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White.copy(alpha = 0.94f)),
            shape = RoundedCornerShape(18.dp),
        ) {
            Column(
                modifier = Modifier.padding(18.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Icon(Icons.Rounded.Map, contentDescription = null, tint = IsatuBlue, modifier = Modifier.size(34.dp))
                Text("Campus map connection ready", style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
                Text(
                    "Add the institutional map key, campus boundary, and office coordinates to activate the live map.",
                    color = MutedInk,
                    style = MaterialTheme.typography.bodySmall,
                    textAlign = TextAlign.Center,
                )
            }
        }

        Column(
            modifier = Modifier.align(Alignment.TopEnd).padding(top = 100.dp, end = 34.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Icon(Icons.Rounded.LocationOn, contentDescription = "Destination marker", tint = Color(0xFFD92D20), modifier = Modifier.size(42.dp))
            Surface(shape = RoundedCornerShape(8.dp), color = Color.White.copy(alpha = 0.9f)) {
                Text(destination, modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp), style = MaterialTheme.typography.labelSmall)
            }
        }

        Box(
            modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = 32.dp).size(54.dp)
                .background(IsatuBlue.copy(alpha = 0.22f), CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.Rounded.MyLocation,
                contentDescription = "Current visitor location",
                tint = IsatuBlue,
                modifier = Modifier.size(30.dp),
            )
        }
    }
}
