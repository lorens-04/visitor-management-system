package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ph.edu.isatu.visitor.R

@Composable
fun PortalBrand(modifier: Modifier = Modifier, subtitle: String? = null) {
    Row(
        modifier = modifier,
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Image(
            painter = painterResource(R.drawable.isatu_logo),
            contentDescription = "ISATU logo",
            modifier = Modifier.size(42.dp),
        )
        Column {
            Text(
                "ISATU Visitor Portal",
                style = MaterialTheme.typography.titleMedium,
                color = IsatuBlueDark,
            )
            subtitle?.let {
                Text(it, style = MaterialTheme.typography.bodySmall, color = MutedInk)
            }
        }
    }
}

@Composable
fun PrimaryButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
) {
    Button(
        onClick = onClick,
        modifier = modifier.height(52.dp),
        enabled = enabled,
        shape = RoundedCornerShape(12.dp),
        colors = ButtonDefaults.buttonColors(containerColor = IsatuBlue),
    ) {
        Text(text, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
fun LoadingBlock(label: String = "Loading…") {
    Column(
        modifier = Modifier.fillMaxWidth().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        CircularProgressIndicator(color = IsatuBlue)
        Text(label, color = MutedInk)
    }
}

@Composable
fun EmptyState(title: String, body: String) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(16.dp),
    ) {
        Column(Modifier.padding(24.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium)
            Text(body, color = MutedInk)
        }
    }
}

@Composable
fun StatusPill(status: String) {
    val (background, foreground) = when (status) {
        "approved", "checked_in" -> Color(0xFFDCFCE7) to IsatuGreen
        "pending_approval", "reschedule_proposed" -> Color(0xFFFFF4CC) to Color(0xFF9A6700)
        "rejected", "cancelled" -> Color(0xFFFEE2E2) to Color(0xFFB42318)
        else -> Color(0xFFE8EEF7) to Color(0xFF475569)
    }
    Box(
        modifier = Modifier.background(background, RoundedCornerShape(50)).padding(horizontal = 10.dp, vertical = 5.dp),
    ) {
        Text(statusLabel(status), color = foreground, style = MaterialTheme.typography.labelLarge)
    }
}

fun statusLabel(status: String): String = when (status) {
    "pending_approval" -> "Pending approval"
    "reschedule_proposed" -> "New schedule offered"
    "checked_in" -> "Checked in"
    "window_closed", "completed" -> "Appointment done"
    "unanswered" -> "Response period ended"
    else -> status.replace('_', ' ').replaceFirstChar { it.uppercase() }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DetailScaffold(
    title: String,
    onBack: () -> Unit,
    content: @Composable (Modifier) -> Unit,
) {
    Scaffold(
        containerColor = AppBackground,
        topBar = {
            TopAppBar(
                title = { Text(title, fontWeight = FontWeight.SemiBold, color = IsatuBlueDark) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Color.White),
            )
        },
    ) { padding -> content(Modifier.padding(padding)) }
}

@Composable
fun MessageCard(message: String, error: Boolean, onDismiss: () -> Unit) {
    val background = if (error) Color(0xFFFFE8E6) else Color(0xFFE8F5E9)
    val foreground = if (error) Color(0xFF9B1C1C) else Color(0xFF166534)
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = background),
        shape = RoundedCornerShape(14.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(start = 16.dp, top = 10.dp, bottom = 10.dp, end = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(message, color = foreground, modifier = Modifier.weight(1f))
            IconButton(onClick = onDismiss, modifier = Modifier.size(40.dp)) {
                Icon(Icons.Rounded.Close, contentDescription = "Dismiss", tint = foreground)
            }
        }
    }
}
